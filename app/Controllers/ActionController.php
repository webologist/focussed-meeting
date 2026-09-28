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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) { flash('error', 'Pick a due date.'); back(); }
        if ($ownerId !== uid() && !is_admin()) { flash('error', 'Only admins can assign tasks to other people.'); back(); }
        $owner = Access::member($ownerId);
        if (!$owner || $owner['status'] === 'disabled') { flash('error', 'Choose someone from your team.'); back(); }
        $dep = (int)input('depends_on', 0);
        if ($dep) Access::action($dep); // ensure visible & same company
        Actions::create(['title' => $title, 'owner_id' => $ownerId, 'owner_name' => $owner['name'], 'deadline' => $deadline, 'priority' => $priority, 'depends_on' => $dep ?: null]);
        flash('success', $ownerId === uid() ? 'Added to your to-do list.' : 'Task assigned to ' . $owner['name'] . '. They’ve been notified.');
        redirect($ownerId === uid() ? 'dashboard' : 'dashboard?tab=assigned');
    }

    public function tracker(): void
    {
        [$scope, $params] = Access::actionScope('a');
        $quick = in_array($_GET['q'] ?? '', ['open', 'overdue', 'high', 'week', 'all'], true) ? $_GET['q'] : 'open';
        $owner = (int)($_GET['owner'] ?? 0);
        $pri = in_array($_GET['priority'] ?? '', ['high', 'medium', 'low'], true) ? $_GET['priority'] : '';
        $today = today();

        $sql = "SELECT a.*, u.name AS owner_name, c.name AS creator_name, m.title AS meeting_title, m.meeting_date,
                       (SELECT COUNT(*) FROM action_notes n WHERE n.action_id = a.id) AS notes_count,
                       (SELECT COUNT(*) FROM note_images ni JOIN action_notes n2 ON n2.id = ni.note_id WHERE n2.action_id = a.id) AS images_count,
                       (SELECT COUNT(*) FROM action_comments k WHERE k.action_id = a.id AND k.is_system = 0) AS comments_count,
                       (SELECT COUNT(*) FROM action_dependencies d JOIN actions b ON b.id = d.depends_on_id WHERE d.action_id = a.id AND b.status <> 'done') AS blockers
                  FROM actions a JOIN users u ON u.id = a.owner_id JOIN users c ON c.id = a.created_by LEFT JOIN meetings m ON m.id = a.meeting_id
                 WHERE $scope";
        $all = DB::all($sql, $params);
        $open = array_values(array_filter($all, fn($a) => $a['status'] === 'open'));
        $counts = [
            'open' => count($open),
            'overdue' => count(array_filter($open, fn($a) => Actions::effDeadline($a) < $today)),
            'high' => count(array_filter($open, fn($a) => $a['priority'] === 'high')),
            'week' => count(array_filter($open, fn($a) => Actions::effDeadline($a) >= $today && days_between(Actions::effDeadline($a), $today) <= 7)),
        ];
        $list = match ($quick) {
            'all' => $all,
            'overdue' => array_filter($open, fn($a) => Actions::effDeadline($a) < $today),
            'high' => array_filter($open, fn($a) => $a['priority'] === 'high'),
            'week' => array_filter($open, fn($a) => Actions::effDeadline($a) >= $today && days_between(Actions::effDeadline($a), $today) <= 7),
            default => $open,
        };
        if ($owner) $list = array_filter($list, fn($a) => (int)$a['owner_id'] === $owner);
        if ($pri) $list = array_filter($list, fn($a) => $a['priority'] === $pri);
        usort($list, function ($x, $y) {
            $p = ['high' => 0, 'medium' => 1, 'low' => 2];
            return [$x['status'] === 'done', $x['status'] === 'done' ? -strtotime((string)$x['done_at']) : 0, Actions::effDeadline($x), $p[$x['priority']]]
               <=> [$y['status'] === 'done', $y['status'] === 'done' ? -strtotime((string)$y['done_at']) : 0, Actions::effDeadline($y), $p[$y['priority']]];
        });
        $owners = [];
        foreach ($all as $a) $owners[(int)$a['owner_id']] = $a['owner_name'];
        asort($owners);
        $lateNudge = count(array_filter($open, fn($a) => Actions::effDeadline($a) < $today && Access::canNudge($a)));
        echo view('actions/tracker', compact('list', 'counts', 'quick', 'owner', 'pri', 'owners', 'lateNudge') + ['title' => 'Action tracker']);
    }

    public function show(int $id): void
    {
        $a = Access::action($id);
        $a['owner'] = DB::one('SELECT id, name, email FROM users WHERE id = ?', [$a['owner_id']]);
        $a['creator'] = DB::one('SELECT id, name FROM users WHERE id = ?', [$a['created_by']]);
        $meeting = $a['meeting_id'] ? DB::one('SELECT id, title, meeting_date FROM meetings WHERE id = ?', [$a['meeting_id']]) : null;
        $point = $a['agenda_item_id'] ? DB::one('SELECT title, discussion FROM agenda_items WHERE id = ?', [$a['agenda_item_id']]) : null;
        $notes = DB::all('SELECT n.*, u.name FROM action_notes n JOIN users u ON u.id = n.user_id WHERE n.action_id = ? ORDER BY n.created_at DESC, n.id DESC', [$id]);
        $images = [];
        if ($notes) {
            [$in, $p] = DB::in(array_column($notes, 'id'));
            foreach (DB::all("SELECT id, note_id FROM note_images WHERE note_id IN $in ORDER BY id", $p) as $img) $images[(int)$img['note_id']][] = (int)$img['id'];
        }
        $comments = DB::all('SELECT k.*, u.name FROM action_comments k JOIN users u ON u.id = k.user_id WHERE k.action_id = ? ORDER BY k.created_at, k.id', [$id]);
        $deps = Actions::dependencies($id);
        $blocks = Actions::dependents($id);
        [$scope, $sp] = Access::actionScope('x');
        $depIds = array_column($deps, 'id');
        $cands = array_filter(DB::all("SELECT x.id, x.title, u.name AS owner_name FROM actions x JOIN users u ON u.id = x.owner_id WHERE x.status = 'open' AND x.id <> :self AND $scope ORDER BY x.title LIMIT 300", ['self' => $id] + $sp),
            fn($c) => !in_array($c['id'], $depIds) && !Actions::createsCycle($id, (int)$c['id']));
        $from = in_array($_GET['from'] ?? '', ['dashboard', 'tracker', 'meetings'], true) ? $_GET['from'] : 'tracker';
        echo view('actions/show', compact('a', 'meeting', 'point', 'notes', 'images', 'comments', 'deps', 'blocks', 'cands', 'from') + ['title' => $a['title']]);
    }

    public function toggle(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canEditAction($a)) abort(403);
        [$ok, $msg] = Actions::setDone($a, input('done') === '1');
        flash($ok ? 'success' : 'error', $msg);
        back('tracker');
    }

    public function deadline(int $id): void
    {
        $a = Access::action($id);
        if (!Access::canEditAction($a) || $a['status'] === 'done') abort(403);
        $d = (string)input('new_deadline');
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { flash('error', 'That date isn’t valid.'); back(); }
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

    /** Assignee notes with images. Only the responsible person may add them. */
    public function note(int $id): void
    {
        $a = Access::action($id);
        if ((int)$a['owner_id'] !== uid()) abort(403, 'Only the person responsible can add notes to this item.');
        $body = (string)input('body');
        $files = self::uploadedImages();
        if ($body === '' && !$files) { flash('error', 'Write a note or add an image first.'); redirect('actions/' . $id); }
        $max = (int)config('uploads.max_per_note', 6);
        if (count($files) > $max) { flash('error', "Up to $max images per note."); redirect('actions/' . $id); }
        $noteId = DB::insert('action_notes', ['action_id' => $id, 'user_id' => uid(), 'body' => $body ?: null, 'created_at' => now_utc()]);
        $dir = STORAGE_PATH . '/uploads/' . org_id();
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $saved = 0;
        foreach ($files as $f) {
            $err = self::validateImage($f);
            if ($err) { flash('error', $f['name'] . ': ' . $err); continue; }
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$f['mime']];
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], "$dir/$name")) {
                DB::insert('note_images', ['note_id' => $noteId, 'org_id' => org_id(), 'stored_name' => $name, 'mime' => $f['mime'], 'size_bytes' => $f['size'], 'created_at' => now_utc()]);
                $saved++;
            }
        }
        if ((int)$a['created_by'] !== uid()) Notifier::inApp((int)$a['created_by'], user()['name'] . ' added a note to "' . $a['title'] . '"', 'actions/' . $id);
        Audit::log('action.note', 'action', $id, ['images' => $saved]);
        flash('success', 'Note added' . ($saved ? ' with ' . plural($saved, 'image') : '') . '.');
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

    /** Serve an uploaded image after checking the viewer can see the action. */
    public function image(int $imageId): void
    {
        $img = DB::one('SELECT i.*, n.action_id FROM note_images i JOIN action_notes n ON n.id = i.note_id WHERE i.id = ? AND i.org_id = ?', [$imageId, org_id()]);
        if (!$img) abort(404);
        Access::action((int)$img['action_id']);
        $file = STORAGE_PATH . '/uploads/' . (int)$img['org_id'] . '/' . basename($img['stored_name']);
        if (!is_file($file)) abort(404);
        header('Content-Type: ' . $img['mime']);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: inline');
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
        $late = array_filter(DB::all("SELECT a.* FROM actions a WHERE a.status = 'open' AND COALESCE(a.new_deadline, a.deadline) < :today AND $scope", ['today' => today()] + $params), [Access::class, 'canNudge']);
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

    private static function uploadedImages(): array
    {
        $out = [];
        $f = $_FILES['images'] ?? null;
        if (!$f || !is_array($f['name'])) return $out;
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => (string)$name, 'tmp_name' => $f['tmp_name'][$i], 'size' => (int)$f['size'][$i], 'error' => (int)$f['error'][$i],
                      'mime' => is_uploaded_file($f['tmp_name'][$i]) ? (string)(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'][$i]) : ''];
        }
        return $out;
    }

    private static function validateImage(array $f): ?string
    {
        if ($f['error'] !== UPLOAD_ERR_OK) return 'upload failed. Try a smaller file.';
        if ($f['size'] > (int)config('uploads.max_bytes', 5242880)) return 'image is larger than ' . round(config('uploads.max_bytes', 5242880) / 1048576) . ' MB.';
        if (!in_array($f['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return 'only JPG, PNG, WebP or GIF images are allowed.';
        if (@getimagesize($f['tmp_name']) === false) return 'the file isn’t a valid image.';
        return null;
    }
}
