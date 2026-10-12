<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class Actions
{
    /** open = to do, progress = in progress, review = completed by the owner and waiting for the host's approval. */
    public const STATUSES = ['open' => 'To do', 'progress' => 'In progress', 'review' => 'Awaiting approval', 'done' => 'Done'];

    public static function effDeadline(array $a): string { return $a['new_deadline'] ?: $a['deadline']; }

    /** Still being worked on (not handed in for approval, not done). */
    public static function isActive(array $a): bool { return in_array($a['status'], ['open', 'progress'], true); }

    public static function isOverdue(array $a): bool { return self::isActive($a) && self::effDeadline($a) < today(); }

    public static function statusPill(array $a): string
    {
        if ($a['status'] === 'done') return '<span class="pill s-done">Done</span>';
        if ($a['status'] === 'review') return '<span class="pill s-review">Awaiting approval</span>';
        if (self::isOverdue($a)) return '<span class="pill p-high">Overdue</span>';
        return $a['status'] === 'progress' ? '<span class="pill s-sched">In progress</span>' : '<span class="pill s-neutral">To do</span>';
    }

    public static function blockers(int $actionId): array
    {
        return DB::all("SELECT a.id, a.title, a.status, a.owner_id FROM action_dependencies d JOIN actions a ON a.id = d.depends_on_id
                         WHERE d.action_id = ? AND a.status <> 'done'", [$actionId]);
    }

    public static function dependencies(int $actionId): array
    {
        return DB::all('SELECT a.*, u.name AS owner_name FROM action_dependencies d JOIN actions a ON a.id = d.depends_on_id JOIN users u ON u.id = a.owner_id WHERE d.action_id = ? ORDER BY a.deadline', [$actionId]);
    }

    public static function dependents(int $actionId): array
    {
        return DB::all('SELECT a.*, u.name AS owner_name FROM action_dependencies d JOIN actions a ON a.id = d.action_id JOIN users u ON u.id = a.owner_id WHERE d.depends_on_id = ? ORDER BY a.deadline', [$actionId]);
    }

    /** Would adding "$actionId waits on $dependsOn" create a cycle? */
    public static function createsCycle(int $actionId, int $dependsOn): bool
    {
        if ($actionId === $dependsOn) return true;
        $seen = [];
        $stack = [$dependsOn];
        while ($stack) {
            $cur = array_pop($stack);
            if ($cur === $actionId) return true;
            if (isset($seen[$cur])) continue;
            $seen[$cur] = true;
            foreach (DB::all('SELECT depends_on_id FROM action_dependencies WHERE action_id = ?', [$cur]) as $r) $stack[] = (int)$r['depends_on_id'];
        }
        return false;
    }

    public static function systemComment(int $actionId, string $body): void
    {
        DB::insert('action_comments', ['action_id' => $actionId, 'user_id' => uid(), 'body' => $body, 'is_system' => 1, 'created_at' => now_utc()]);
    }

    private static function comment(int $actionId, string $body): void
    {
        if (trim($body) !== '') DB::insert('action_comments', ['action_id' => $actionId, 'user_id' => uid(), 'body' => mb_substr(trim($body), 0, 4000), 'created_at' => now_utc()]);
    }

    /**
     * Owner moves their item between To do, In progress and Completed.
     * "Completed" goes to the host for approval, unless the owner is the approver. Returns [ok, message].
     */
    public static function move(array $a, string $to, string $note = ''): array
    {
        if (!in_array($to, ['open', 'progress', 'review'], true)) return [false, 'Unknown status.'];
        if ($a['status'] === 'done') return [false, 'This item is already approved. Ask the host to reopen it.'];
        if ($to === $a['status']) return [true, 'No change.'];
        if ($to === 'review') {
            if ($b = self::blockers((int)$a['id'])) return [false, 'Waiting on "' . $b[0]['title'] . '". Finish that first or remove the dependency.'];
            $approver = Access::approverId($a);
            if ($approver === uid()) return self::approve($a, $note);
            DB::update('actions', ['status' => 'review', 'submitted_at' => now_utc(), 'updated_at' => now_utc()], ['id' => $a['id']]);
            self::systemComment((int)$a['id'], 'Marked complete. Waiting for approval');
            self::comment((int)$a['id'], $note);
            Notifier::inApp($approver, user()['name'] . ' completed "' . $a['title'] . '". Please review and approve.', 'actions/' . $a['id']);
            Audit::log('action.submitted', 'action', (int)$a['id']);
            return [true, 'Marked complete and sent to ' . self::name($approver) . ' for approval.'];
        }
        DB::update('actions', ['status' => $to, 'submitted_at' => null, 'updated_at' => now_utc()], ['id' => $a['id']]);
        self::systemComment((int)$a['id'], $a['status'] === 'review' ? 'Taken back from approval, now ' . mb_strtolower(self::STATUSES[$to]) : 'Moved to ' . mb_strtolower(self::STATUSES[$to]));
        self::comment((int)$a['id'], $note);
        return [true, 'Moved to ' . self::STATUSES[$to] . '.'];
    }

    /** Host approves: the item is done. Unblocks anything waiting on it. */
    public static function approve(array $a, string $note = ''): array
    {
        if ($b = self::blockers((int)$a['id'])) return [false, 'Waiting on "' . $b[0]['title'] . '". Finish that first or remove the dependency.'];
        $selfDone = (int)$a['owner_id'] === uid();
        DB::update('actions', ['status' => 'done', 'done_at' => now_utc(), 'approved_by' => uid(), 'submitted_at' => $a['submitted_at'] ?: now_utc(), 'updated_at' => now_utc()], ['id' => $a['id']]);
        self::systemComment((int)$a['id'], $selfDone ? 'Marked complete' : 'Approved as complete');
        self::comment((int)$a['id'], $note);
        $freed = 0;
        foreach (self::dependents((int)$a['id']) as $d) {
            if ($d['status'] !== 'done' && !self::blockers((int)$d['id'])) {
                $freed++;
                self::systemComment((int)$d['id'], 'Unblocked: "' . $a['title'] . '" is done');
                if ((int)$d['owner_id'] !== uid()) Notifier::inApp((int)$d['owner_id'], 'You can start "' . $d['title'] . '". What it was waiting on is done.', 'actions/' . $d['id']);
            }
        }
        if (!$selfDone) Notifier::inApp((int)$a['owner_id'], user()['name'] . ' approved "' . $a['title'] . '"' . (trim($note) !== '' ? ': ' . trim($note) : ''), 'actions/' . $a['id']);
        foreach (array_unique([(int)$a['created_by'], Access::approverId($a)]) as $to) {
            if ($to !== uid() && $to !== (int)$a['owner_id']) Notifier::inApp($to, user()['name'] . ' completed "' . $a['title'] . '"', 'actions/' . $a['id']);
        }
        Audit::log('action.completed', 'action', (int)$a['id']);
        return [true, ($selfDone ? 'Marked complete.' : 'Approved.') . ($freed ? ' ' . plural($freed, 'item') . ' unblocked.' : '')];
    }

    /** Host sends a completed item back to the owner, with a reason. */
    public static function sendBack(array $a, string $note): array
    {
        if (trim($note) === '') return [false, 'Tell ' . self::name((int)$a['owner_id']) . ' what still needs doing.'];
        DB::update('actions', ['status' => 'progress', 'submitted_at' => null, 'updated_at' => now_utc()], ['id' => $a['id']]);
        self::systemComment((int)$a['id'], 'Sent back for more work');
        self::comment((int)$a['id'], $note);
        if ((int)$a['owner_id'] !== uid()) Notifier::inApp((int)$a['owner_id'], user()['name'] . ' sent back "' . $a['title'] . '": ' . trim($note), 'actions/' . $a['id']);
        Audit::log('action.sent_back', 'action', (int)$a['id']);
        return [true, 'Sent back to ' . self::name((int)$a['owner_id']) . '.'];
    }

    /** Host or admin reopens an approved item. */
    public static function reopen(array $a): array
    {
        DB::update('actions', ['status' => 'open', 'done_at' => null, 'approved_by' => null, 'submitted_at' => null, 'updated_at' => now_utc()], ['id' => $a['id']]);
        self::systemComment((int)$a['id'], 'Reopened');
        if ((int)$a['owner_id'] !== uid()) Notifier::inApp((int)$a['owner_id'], user()['name'] . ' reopened "' . $a['title'] . '"', 'actions/' . $a['id']);
        Audit::log('action.reopened', 'action', (int)$a['id']);
        return [true, 'Reopened.'];
    }

    /** Hand the item to another team member. */
    public static function reassign(array $a, array $to, string $note = ''): array
    {
        if ((int)$to['id'] === (int)$a['owner_id']) return [false, $to['name'] . ' is already responsible for this item.'];
        $from = self::name((int)$a['owner_id']);
        DB::update('actions', ['owner_id' => $to['id'], 'status' => $a['status'] === 'review' ? 'progress' : $a['status'], 'submitted_at' => null,
            'last_due_reminder_on' => null, 'last_overdue_alert_on' => null, 'updated_at' => now_utc()], ['id' => $a['id']]);
        self::systemComment((int)$a['id'], 'Reassigned from ' . $from . ' to ' . $to['name']);
        self::comment((int)$a['id'], $note);
        if ((int)$to['id'] !== uid()) {
            Notifier::inApp((int)$to['id'], user()['name'] . ' handed you "' . $a['title'] . '" due ' . fmt_date(self::effDeadline($a)) . (trim($note) !== '' ? ': ' . trim($note) : ''), 'actions/' . $a['id']);
            if ($to['status'] === 'active') {
                $html = Notifier::layout('A task was handed to you', '<p>' . e(user()['name']) . ' handed you this item (previously with ' . e($from) . '):</p><p style="font-size:16px"><b>' . e($a['title']) . '</b></p><p>Due: ' . e(fmt_date(self::effDeadline($a))) . '</p>' . (trim($note) !== '' ? '<p style="white-space:pre-wrap">' . e(trim($note)) . '</p>' : ''), 'Open task', url('actions/' . $a['id']), user()['org_name']);
                Notifier::email(org_id(), $to['email'], $to['name'], 'Handed to you: ' . $a['title'], $html);
            }
        }
        if ((int)$a['owner_id'] !== uid()) Notifier::inApp((int)$a['owner_id'], user()['name'] . ' reassigned "' . $a['title'] . '" to ' . $to['name'], 'actions/' . $a['id']);
        Audit::log('action.reassigned', 'action', (int)$a['id'], ['from' => (int)$a['owner_id'], 'to' => (int)$to['id']]);
        return [true, 'Reassigned to ' . $to['name'] . '. They’ve been notified.'];
    }

    public static function setNewDeadline(array $a, ?string $date): void
    {
        $old = self::effDeadline($a);
        $new = ($date && $date !== $a['deadline']) ? $date : null;
        DB::update('actions', ['new_deadline' => $new, 'updated_at' => now_utc()], ['id' => $a['id']]);
        $eff = $new ?: $a['deadline'];
        if ($eff !== $old) {
            self::systemComment((int)$a['id'], $new ? 'Deadline moved from ' . fmt_date($old) . ' to ' . fmt_date($new) : 'Revised deadline cleared, back to ' . fmt_date($a['deadline']));
        }
    }

    /** Create an action item or task. */
    public static function create(array $data): int
    {
        $id = DB::insert('actions', [
            'org_id' => org_id(), 'project_id' => $data['project_id'] ?? null, 'meeting_id' => $data['meeting_id'] ?? null, 'agenda_item_id' => $data['agenda_item_id'] ?? null,
            'title' => mb_substr($data['title'], 0, 250), 'details' => $data['details'] ?? null, 'priority' => $data['priority'],
            'owner_id' => $data['owner_id'], 'created_by' => uid(), 'deadline' => $data['deadline'], 'status' => 'open', 'created_at' => now_utc(),
        ]);
        if (!empty($data['depends_on'])) {
            DB::insert('action_dependencies', ['action_id' => $id, 'depends_on_id' => (int)$data['depends_on']]);
        }
        if ((int)$data['owner_id'] !== uid()) {
            self::systemComment($id, 'Assigned to ' . ($data['owner_name'] ?? 'owner'));
            Notifier::inApp((int)$data['owner_id'], user()['name'] . ' assigned you "' . $data['title'] . '" due ' . fmt_date($data['deadline']), 'actions/' . $id);
            $owner = Access::member((int)$data['owner_id']);
            if ($owner && $owner['status'] === 'active' && empty($data['quiet'])) {
                $html = Notifier::layout('New task for you', '<p>' . e(user()['name']) . ' assigned you:</p><p style="font-size:16px"><b>' . e($data['title']) . '</b></p><p>Priority: ' . e(ucfirst($data['priority'])) . '<br>Due: ' . e(fmt_date($data['deadline'])) . '</p>', 'Open task', url('actions/' . $id), user()['org_name']);
                Notifier::email(org_id(), $owner['email'], $owner['name'], 'New task: ' . $data['title'], $html);
            }
        }
        Audit::log('action.created', 'action', $id);
        return $id;
    }

    private static function name(int $userId): string { return (string)(DB::value('SELECT name FROM users WHERE id = ?', [$userId]) ?? 'the host'); }
}
