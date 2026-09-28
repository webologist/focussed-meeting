<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class Actions
{
    public static function effDeadline(array $a): string { return $a['new_deadline'] ?: $a['deadline']; }

    public static function isOverdue(array $a): bool { return $a['status'] !== 'done' && self::effDeadline($a) < today(); }

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

    /** Mark done / reopen. Returns [ok, message]. */
    public static function setDone(array $a, bool $done): array
    {
        if ($done && ($b = self::blockers((int)$a['id']))) {
            return [false, 'Waiting on "' . $b[0]['title'] . '". Finish that first or remove the dependency.'];
        }
        DB::update('actions', ['status' => $done ? 'done' : 'open', 'done_at' => $done ? now_utc() : null, 'updated_at' => now_utc()], ['id' => $a['id']]);
        self::systemComment((int)$a['id'], $done ? 'Marked complete' : 'Reopened');
        $freed = 0;
        if ($done) {
            foreach (self::dependents((int)$a['id']) as $d) {
                if ($d['status'] !== 'done' && !self::blockers((int)$d['id'])) {
                    $freed++;
                    self::systemComment((int)$d['id'], 'Unblocked: "' . $a['title'] . '" is done');
                    if ((int)$d['owner_id'] !== uid()) Notifier::inApp((int)$d['owner_id'], 'You can start "' . $d['title'] . '". What it was waiting on is done.', 'actions/' . $d['id']);
                }
            }
            if ((int)$a['created_by'] !== uid()) Notifier::inApp((int)$a['created_by'], user()['name'] . ' completed "' . $a['title'] . '"', 'actions/' . $a['id']);
        }
        Audit::log($done ? 'action.completed' : 'action.reopened', 'action', (int)$a['id']);
        return [true, $done ? 'Marked complete.' . ($freed ? ' ' . plural($freed, 'item') . ' unblocked.' : '') : 'Reopened.'];
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
            'org_id' => org_id(), 'meeting_id' => $data['meeting_id'] ?? null, 'agenda_item_id' => $data['agenda_item_id'] ?? null,
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
}
