<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Access;
use App\Services\Actions;
use App\Services\Audit;
use App\Services\Notifier;

final class ActionController
{
    /** Quick add from the dashboard: a to-do for me, or (admins) a task for someone else. */
    public function storeTask(): void
    {
        $title = (string)input('title');
        $ownerId = (int)input('owner_id', uid());
        $deadline = (string)input('deadline');
        $priority = in_array(input('priority'), ['high', 'medium', 'low'], true) ? input('priority') : 'medium';
        if ($title === '') { flash('error', 'Describe the task first.'); back(); }
        if (!valid_date($deadline)) { flash('error', 'Pick a due date.'); back(); }
        if ($ownerId !== uid() && !is_admin()) { flash('error', 'Only admins can assign tasks to other people.'); back(); }
        $owner = Access::member($ownerId);
        if (!$owner || $owner['status'] === 'disabled') { flash('error', 'Choose someone from your team.'); back(); }
        $dep = (int)input('depends_on', 0);
        if ($dep) Access::action($dep); // ensure visible & same company
        $project = Access::project((int)input('project_id', 0));
        Actions::create(['title' => $title, 'owner_id' => $ownerId, 'owner_name' => $owner['name'], 'deadline' => $deadline, 'priority' => $priority, 'depends_on' => $dep ?: null, 'project_id' => $project['id'] ?? null]);
        flash('success', $ownerId === uid() ? 'Added to your to-do list.' : 'Task assigned to ' . $owner['name'] . '. They’ve been notified.');
        redirect($ownerId === uid() ? 'dashboard' : 'dashboard?tab=assigned');
    }

    public function tracker(): void
    {
        [$scope, $params] = Access::actionScope('a');
        $quick = in_array($_GET['q'] ?? '', ['open', 'overdue', 'high', 'week', 'review', 'done', 'all'], true) ? $_GET['q'] : 'open';
        $owner = (int)($_GET['owner'] ?? 0);
        $pri = in_array($_GET['priority'] ?? '', ['high', 'medium', 'low'], true) ? $_GET['priority'] : '';
        $project = (int)($_GET['project'] ?? 0);
        $sort = in_array($_GET['sort'] ?? '', ['deadline', 'assignee', 'status', 'priority', 'title'], true) ? $_GET['sort'] : 'deadline';
        $dir = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $today = today();

        $sql = "SELECT a.*, u.name AS owner_name, c.name AS creator_name, m.title AS meeting_title, m.meeting_date, m.organizer_id, p.name AS project_name,
                       COALESCE(m.organizer_id, a.created_by) AS approver_id, ap.name AS approver_name,
                       (SELECT COUNT(*) FROM action_notes n WHERE n.action_id = a.id) AS notes_count,
                       (SELECT COUNT(*) FROM note_images ni JOIN action_notes n2 ON n2.id = ni.note_id WHERE n2.action_id = a.id) AS images_count,
                       (SELECT COUNT(*) FROM action_comments k WHERE k.action_id = a.id AND k.is_system = 0) AS comments_count,
                       (SELECT COUNT(*) FROM action_dependencies d JOIN actions b ON b.id = d.depends_on_id WHERE d.action_id = a.id AND b.status <> 'done') AS blockers
                  FROM actions a JOIN users u ON u.id = a.owner_id JOIN users c ON c.id = a.created_by
                  LEFT JOIN meetings m ON m.id = a.meeting_id LEFT JOIN projects p ON p.id = a.project_id
                  JOIN users ap ON ap.id = COALESCE(m.organizer_id, a.created_by)
                 WHERE $scope";
        $all = DB::all($sql, $params);
        $open = array_values(array_filter($all, fn($a) => $a['status'] !== 'done'));
        $inWeek = fn($a) => Actions::isActive($a) && Actions::effDeadline($a) >= $today && days_between(Actions::effDeadline($a), $today) <= 7;
        $myReview = fn($a) => $a['status'] === 'review' && (is_admin() || (int)$a['approver_id'] === uid());
        $counts = [
            'open' => count($open),
            'overdue' => count(array_filter($open, [Actions::class, 'isOverdue'])),
            'review' => count(array_filter($open, $myReview)),
            'week' => count(array_filter($open, $inWeek)),
            'all' => count($all),
        ];
        $list = match ($quick) {
            'all' => $all,
            'done' => array_filter($all, fn($a) => $a['status'] === 'done'),
            'overdue' => array_filter($open, [Actions::class, 'isOverdue']),
            'high' => array_filter($open, fn($a) => $a['priority'] === 'high'),
            'week' => array_filter($open, $inWeek),
            'review' => array_filter($open, $myReview),
            default => $open,
        };
        if ($owner) $list = array_filter($list, fn($a) => (int)$a['owner_id'] === $owner);
        if ($pri) $list = array_filter($list, fn($a) => $a['priority'] === $pri);
        if ($project) $list = array_filter($list, fn($a) => (int)$a['project_id'] === $project);
        $pOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        $sOrder = ['open' => 0, 'progress' => 1, 'review' => 2, 'done' => 3];
        usort($list, function ($x, $y) use ($sort, $dir, $pOrder, $sOrder) {
            $key = fn($a) => match ($sort) {
                'assignee' => [mb_strtolower($a['owner_name'])],
                'status' => [$sOrder[$a['status']]],
                'priority' => [$pOrder[$a['priority']]],
                'title' => [mb_strtolower($a['title'])],
                default => [$a['status'] === 'done' ? 1 : 0, Actions::effDeadline($a)],
            };
            $c = $key($x) <=> $key($y);
            if ($dir === 'desc') $c = -$c;
            // Ties: earliest deadline first, then highest priority
            return $c ?: ([Actions::effDeadline($x), $pOrder[$x['priority']]] <=> [Actions::effDeadline($y), $pOrder[$y['priority']]]);
        });
        $owners = [];
        foreach ($all as $a) $owners[(int)$a['owner_id']] = $a['owner_name'];
        asort($owners);
        $projects = Access::projects(true);
        $lateNudge = count(array_filter($open, fn($a) => Actions::isOverdue($a) && Access::canNudge($a)));
        echo view('actions/tracker', compact('list', 'counts', 'quick', 'owner', 'pri', 'project', 'projects', 'sort', 'dir', 'owners', 'lateNudge') + ['title' => 'All tasks']);
    }

    public function show(int $id): void
    {
        $a = Access::action($id);
        // The assignee opening a task someone else gave them marks it as seen
        if ((int)$a['owner_id'] === uid() && (int)$a['created_by'] !== uid() && empty($a['seen_at'])) {
            DB::run('UPDATE actions SET seen_at = UTC_TIMESTAMP() WHERE id = ? AND seen_at IS NULL', [$id]);
            $a['seen_at'] = now_utc();
        }
        $a['owner'] = DB::one('SELECT id, name, email FROM users WHERE id = ?', [$a['owner_id']]);
        $a['creator'] = DB::one('SELECT id, name FROM users WHERE id = ?', [$a['created_by']]);
        $meeting = $a['meeting_id'] ? DB::one('SELECT id, title, meeting_date FROM meetings WHERE id = ?', [$a['meeting_id']]) : null;
        $point = $a['agenda_item_id'] ? DB::one('SELECT title, discussion FROM agenda_items WHERE id = ?', [$a['agenda_item_id']]) : null;
        $notes = DB::all('SELECT n.*, u.name FROM action_notes n JOIN users u ON u.id = n.user_id WHERE n.action_id = ? ORDER BY n.created_at DESC, n.id DESC', [$id]);
        $images = [];
        if ($notes) {
            [$in, $p] = DB::in(array_column($notes, 'id'));
            foreach (DB::all("SELECT id, note_id, mime, original_name, size_bytes FROM note_images WHERE note_id IN $in ORDER BY id", $p) as $img) $images[(int)$img['note_id']][] = $img;
        }
        $comments = DB::all('SELECT k.*, u.name FROM action_comments k JOIN users u ON u.id = k.user_id WHERE k.action_id = ? ORDER BY k.created_at, k.id', [$id]);
        $deps = Actions::dependencies($id);
        $blocks = Actions::dependents($id);
        [$scope, $sp] = Access::actionScope('x');
        $depIds = array_column($deps, 'id');
        $cands = array_filter(DB::all("SELECT x.id, x.title, u.name AS owner_name FROM actions x JOIN users u ON u.id = x.owner_id WHERE x.status <> 'done' AND x.id <> :self AND $scope ORDER BY x.title LIMIT 300", ['self' => $id] + $sp),
            fn($c) => !in_array($c['id'], $depIds) && !Actions::createsCycle($id, (int)$c['id']));
        $from = in_array($_GET['from'] ?? '', ['dashboard', 'tracker', 'meetings', 'projects'], true) ? $_GET['from'] : 'tracker';
        $approver = DB::one('SELECT id, name FROM users WHERE id = ?', [Access::approverId($a)]);
        $members = Access::canReassign($a) ? array_filter(Access::members(), fn($m) => $m['status'] !== 'disabled') : [];
        $project = Access::project((int)$a['project_id']);
        echo view('actions/show', compact('a', 'meeting', 'point', 'notes', 'images', 'comments', 'deps', 'blocks', 'cands', 'from', 'approver', 'members', 'project') + ['title' => $a['title']]);
    }

    /** List checkbox: the owner marks complete (goes for approval); the host approves or reopens. */
    public function toggle(int $id): void
    {
        $a = Access::action($id);
        $note = (string)input('note');
        if (input('done') === '1') {
            if ($a['status'] === 'review' && Access::canApprove($a)) [$ok, $msg] = Actions::approve($a, $note);
            elseif (Access::canEditAction($a)) [$ok, $msg] = Actions::move($a, 'review', $note);
            else abort(403);
        } else {
            if ($a['status'] === 'done') { if (!Access::canApprove($a)) abort(403, 'Only the host can reopen an approved item.'); [$ok, $msg] = Actions::reopen($a); }
            elseif (Access::canEditAction($a)) [$ok, $msg] = Actions::move($a, 'open', $note);
            else abort(403);
        }
        flash($ok ? 'success' : 'error', $msg);
        back('tracker');
    }

    /** The owner moves their item between To do, In progress and Completed (sent for approval). */
    public function status(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canEditAction($a)) abort(403);
        [$ok, $msg] = Actions::move($a, (string)input('status'), (string)input('note'));
        flash($ok ? 'success' : 'error', $msg);
        back('tracker');
    }

    /** The assignee taps "Got it": the person who gave them the task sees the double tick. */
    public function ack(int $id): void
    {
        $a = Access::action($id);
        if ((int)$a['owner_id'] !== uid() || (int)$a['created_by'] === uid() || $a['status'] === 'done') abort(403);
        if (empty($a['acked_at'])) {
            DB::run('UPDATE actions SET acked_at = UTC_TIMESTAMP(), seen_at = COALESCE(seen_at, UTC_TIMESTAMP()) WHERE id = ? AND acked_at IS NULL', [$id]);
            Actions::systemComment($id, 'Acknowledged by ' . user()['name']);
            Notifier::inApp((int)$a['created_by'], user()['name'] . ' acknowledged "' . $a['title'] . '"', 'actions/' . $id);
        }
        flash('success', 'Got it. They can see you’ve acknowledged this task.');
        back('actions/' . $id);
    }

    public function approve(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canApprove($a) || $a['status'] === 'done') abort(403);
        [$ok, $msg] = Actions::approve($a, (string)input('note'));
        flash($ok ? 'success' : 'error', $msg);
        back('tracker');
    }

    public function sendBack(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canApprove($a) || $a['status'] !== 'review') abort(403);
        [$ok, $msg] = Actions::sendBack($a, (string)input('note'));
        flash($ok ? 'success' : 'error', $msg);
        back('tracker');
    }

    public function reassign(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canReassign($a)) abort(403);
        $to = Access::member((int)input('owner_id'));
        if (!$to || $to['status'] === 'disabled') { flash('error', 'Choose someone from your team.'); back(); }
        [$ok, $msg] = Actions::reassign($a, $to, (string)input('note'));
        flash($ok ? 'success' : 'error', $msg);
        redirect('actions/' . $id);
    }

    public function deadline(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canEditAction($a) || $a['status'] === 'done') abort(403);
        $d = (string)input('new_deadline');
        if ($d !== '' && !valid_date($d)) { flash('error', 'That date isn’t valid.'); back(); }
        Actions::setNewDeadline($a, $d ?: null);
        flash('success', $d ? 'Deadline moved to ' . fmt_date($d) . '.' : 'Revised deadline cleared.');
        back('tracker');
    }

    public function comment(int $id): void
    {
        $a = Access::action($id);
        $body = (string)input('body');
        if ($body === '') back();
        DB::insert('action_comments', ['action_id' => $id, 'user_id' => uid(), 'body' => mb_substr($body, 0, 4000), 'created_at' => now_utc()]);
        foreach (array_unique([(int)$a['owner_id'], (int)$a['created_by']]) as $to) {
            if ($to !== uid()) Notifier::inApp($to, user()['name'] . ' commented on "' . $a['title'] . '"', 'actions/' . $id);
        }
        redirect('actions/' . $id . '#comments');
    }

    /** Document types that can be attached next to images. The extension decides, and the file content is checked against it. */
    private const DOC_TYPES = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
    ];
    private const IMAGE_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** Notes with images and documents. Anyone who can see the task can add them while it is still open. */
    public function note(int $id): void
    {
        $a = Access::action($id);
        if ($a['status'] === 'done') { flash('error', 'This item is approved, so notes are closed. Reopen it to add more.'); redirect('actions/' . $id); }
        $body = trim((string)input('body'));
        $files = self::uploadedFiles();
        if ($body === '' && !$files) { flash('error', 'Write a note or attach a file first.'); redirect('actions/' . $id); }
        $max = (int)config('uploads.max_per_note', 6);
        if (count($files) > $max) { flash('error', "Up to $max files per note."); redirect('actions/' . $id); }
        $noteId = DB::insert('action_notes', ['action_id' => $id, 'user_id' => uid(), 'body' => $body !== '' ? mb_substr($body, 0, 8000) : null, 'created_at' => now_utc()]);
        $dir = STORAGE_PATH . '/uploads/' . org_id();
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $images = 0; $docs = 0; $skipped = 0;
        foreach ($files as $f) {
            [$kind, $ext, $mime, $err] = self::classify($f);
            if ($err) { flash('error', $f['name'] . ': ' . $err); $skipped++; continue; }
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], "$dir/$name")) {
                DB::insert('note_images', ['note_id' => $noteId, 'org_id' => org_id(), 'stored_name' => $name, 'original_name' => self::cleanName($f['name']), 'mime' => $mime, 'size_bytes' => $f['size'], 'created_at' => now_utc()]);
                $kind === 'image' ? $images++ : $docs++;
            } else $skipped++;
        }
        if ($body === '' && !$images && !$docs) { DB::delete('action_notes', ['id' => $noteId]); redirect('actions/' . $id); } // nothing usable was saved
        foreach (array_unique([(int)$a['owner_id'], (int)$a['created_by']]) as $to) {
            if ($to !== uid()) Notifier::inApp($to, user()['name'] . ' added a note to "' . $a['title'] . '"' . ($images + $docs ? ' with ' . plural($images + $docs, 'file') : ''), 'actions/' . $id);
        }
        Audit::log('action.note', 'action', $id, ['images' => $images, 'documents' => $docs]);
        $bits = array_filter([$images ? plural($images, 'image') : null, $docs ? plural($docs, 'document') : null]);
        flash('success', 'Note added' . ($bits ? ' with ' . implode(' and ', $bits) : '') . '.');
        redirect('actions/' . $id);
    }

    public function deleteNote(int $id, int $noteId): void
    {
        Access::action($id);
        $n = DB::one('SELECT * FROM action_notes WHERE id = ? AND action_id = ?', [$noteId, $id]);
        if (!$n || ((int)$n['user_id'] !== uid() && !is_admin())) abort(403);
        foreach (DB::all('SELECT stored_name FROM note_images WHERE note_id = ?', [$noteId]) as $img) @unlink(STORAGE_PATH . '/uploads/' . org_id() . '/' . basename($img['stored_name']));
        DB::delete('action_notes', ['id' => $noteId]);
        flash('success', 'Note deleted.');
        redirect('actions/' . $id);
    }

    /** Serve an uploaded image or document after checking the viewer can see the action. Documents always download. */
    public function image(int $imageId): void
    {
        $img = DB::one('SELECT i.*, n.action_id FROM note_images i JOIN action_notes n ON n.id = i.note_id WHERE i.id = ? AND i.org_id = ?', [$imageId, org_id()]);
        if (!$img) abort(404);
        Access::action((int)$img['action_id']);
        $file = STORAGE_PATH . '/uploads/' . (int)$img['org_id'] . '/' . basename($img['stored_name']);
        if (!is_file($file)) abort(404);
        $isImage = str_starts_with((string)$img['mime'], 'image/');
        $label = (string)($img['original_name'] ?: $img['stored_name']);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $label);
        header('Content-Type: ' . $img['mime']);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($label));
        readfile($file);
        exit;
    }

    public function addDependency(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canEditAction($a)) abort(403);
        $dep = Access::action((int)input('depends_on'));
        if (Actions::createsCycle($id, (int)$dep['id'])) { flash('error', 'That would create a loop of items waiting on each other.'); redirect('actions/' . $id); }
        DB::run('INSERT IGNORE INTO action_dependencies (action_id, depends_on_id) VALUES (?, ?)', [$id, $dep['id']]);
        Actions::systemComment($id, 'Now waiting on "' . $dep['title'] . '"');
        flash('success', 'Dependency added.');
        redirect('actions/' . $id);
    }

    public function removeDependency(int $id, int $depId): void
    {
        $a = Access::action($id);
        if (!Access::canEditAction($a)) abort(403);
        $title = (string)DB::value('SELECT title FROM actions WHERE id = ? AND org_id = ?', [$depId, org_id()]);
        DB::delete('action_dependencies', ['action_id' => $id, 'depends_on_id' => $depId]);
        Actions::systemComment($id, 'No longer waiting on "' . $title . '"');
        redirect('actions/' . $id);
    }

    /** Send a reminder to the person responsible. */
    public function nudge(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canNudge($a)) abort(403);
        $sent = self::sendNudge($a, (string)input('message'), !empty($_POST['via_email']), !empty($_POST['via_whatsapp']));
        flash($sent ? 'success' : 'error', $sent ? 'Reminder sent to ' . $sent . '.' : 'Choose email or WhatsApp.');
        back('tracker');
    }

    public function nudgeOverdue(): void
    {
        [$scope, $params] = Access::actionScope('a');
        $late = array_filter(DB::all("SELECT a.* FROM actions a WHERE a.status IN ('open','progress') AND COALESCE(a.new_deadline, a.deadline) < :today AND $scope", ['today' => today()] + $params), [Access::class, 'canNudge']);
        if (input('scope') === 'mine') $late = array_filter($late, fn($a) => (int)$a['created_by'] === uid());
        $people = [];
        foreach ($late as $a) {
            $msg = "Hi, a reminder that \"{$a['title']}\" was due " . fmt_date(Actions::effDeadline($a)) . " and is overdue. Please update the status or add a note.\n\nThanks,\n" . user()['name'];
            if ($n = self::sendNudge($a, $msg, true, true)) $people[$n] = true;
        }
        flash('success', $people ? 'Reminders sent to ' . plural(count($people), 'person', 'people') . '.' : 'No overdue items to remind about.');
        back('tracker');
    }

    public function destroy(int $id): void
    {
        $a = Access::action($id);
        $canDelete = is_admin() || (int)$a['created_by'] === uid();
        if (!$canDelete) abort(403);
        DB::delete('actions', ['id' => $id]);
        Audit::log('action.deleted', 'action', $id, ['title' => $a['title']]);
        flash('success', 'Item deleted.');
        redirect(input('return') === 'meeting' && $a['meeting_id'] ? 'meetings/' . $a['meeting_id'] : 'tracker');
    }

    // ---------------------------------------------------------------

    private static function sendNudge(array $a, string $message, bool $email, bool $wa): ?string
    {
        $owner = DB::one('SELECT id, name, email, phone, status FROM users WHERE id = ?', [$a['owner_id']]);
        if (!$owner || (!$email && !$wa)) return null;
        $message = trim($message) ?: 'A reminder about "' . $a['title'] . '".';
        $channels = [];
        if ($email) {
            $html = Notifier::layout('Reminder: ' . $a['title'], '<p>' . nl2br(e($message)) . '</p><p>Due: <b>' . e(fmt_date(Actions::effDeadline($a))) . '</b></p>', 'Update this item', url('actions/' . $a['id']), user()['org_name']);
            Notifier::email(org_id(), $owner['email'], $owner['name'], 'Reminder: ' . $a['title'], $html);
            $channels[] = 'email';
        }
        if ($wa && $owner['phone'] && Notifier::whatsapp(org_id(), (string)$owner['phone'], $owner['name'], $message . "\n" . url('actions/' . $a['id']))) $channels[] = 'WhatsApp';
        Notifier::inApp((int)$owner['id'], user()['name'] . ' sent you a reminder about "' . $a['title'] . '"', 'actions/' . $a['id']);
        Actions::systemComment((int)$a['id'], 'Reminder sent to ' . $owner['name'] . ($channels ? ' via ' . implode(' and ', $channels) : ' in the app'));
        return $owner['name'];
    }

    /** Every file posted from the note form (the form field is "files", older pages used "images"). */
    private static function uploadedFiles(): array
    {
        $out = [];
        $f = $_FILES['files'] ?? $_FILES['images'] ?? null;
        if (!$f || !is_array($f['name'])) return $out;
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => (string)$name, 'tmp_name' => $f['tmp_name'][$i], 'size' => (int)$f['size'][$i], 'error' => (int)$f['error'][$i],
                      'mime' => is_uploaded_file($f['tmp_name'][$i]) ? (string)(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'][$i]) : ''];
        }
        return $out;
    }

    /** @return array{0:string,1:string,2:string,3:?string} kind (image|doc), extension to store, mime to serve, error */
    private static function classify(array $f): array
    {
        $fail = fn(string $m) => ['', '', '', $m];
        if ($f['error'] !== UPLOAD_ERR_OK) return $fail('upload failed. Try a smaller file.');
        if ($f['size'] <= 0) return $fail('the file is empty.');
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (isset(self::IMAGE_EXT[$f['mime']])) {
            if ($f['size'] > (int)config('uploads.max_bytes', 5242880)) return $fail('image is larger than ' . round(config('uploads.max_bytes', 5242880) / 1048576) . ' MB.');
            if (@getimagesize($f['tmp_name']) === false) return $fail('the file isn’t a valid image.');
            return ['image', self::IMAGE_EXT[$f['mime']], $f['mime'], null];
        }
        if (!isset(self::DOC_TYPES[$ext])) return $fail('this file type isn’t allowed. Use an image, PDF, Word, Excel, PowerPoint, CSV or text file.');
        $max = (int)config('uploads.max_file_bytes', 10485760);
        if ($f['size'] > $max) return $fail('file is larger than ' . round($max / 1048576) . ' MB.');
        $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 4096);
        $ok = match ($ext) {
            'pdf' => str_starts_with($head, '%PDF'),
            'doc', 'xls', 'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'docx', 'xlsx', 'pptx', 'odt', 'ods' => str_starts_with($head, 'PK'),
            default => !str_contains($head, "\0") && mb_check_encoding($head, 'UTF-8') || !str_contains($head, "\0") && str_starts_with((string)$f['mime'], 'text/'),
        };
        if (!$ok) return $fail('the content doesn’t match a ' . strtoupper($ext) . ' file.');
        return ['doc', $ext, self::DOC_TYPES[$ext], null];
    }

    private static function cleanName(string $n): string
    {
        $n = preg_replace('#[\x00-\x1F\x7F\\\\/]+#u', '', basename(str_replace('\\', '/', $n))) ?? '';
        $n = trim($n);
        return mb_substr($n !== '' ? $n : 'file', 0, 150);
    }
}
