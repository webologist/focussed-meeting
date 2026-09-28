<?php
// Background jobs: reminders, deadline alerts, daily digest and message retries.
// Schedule it every 5 minutes (cPanel > Cron Jobs), for example:
//   php /home/USER/focused-meetings/cron/run.php >> /home/USER/focused-meetings/storage/logs/cron.log 2>&1
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Services\Actions;
use App\Services\Notifier;

$lock = fopen(STORAGE_PATH . '/cache/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { echo "Another run is in progress\n"; exit; }

$stats = ['outbox' => 0, 'reminders' => 0, 'due' => 0, 'overdue' => 0, 'digests' => 0];

/** Local date/time for a company timezone. */
function local_now(string $tz): DateTime { return new DateTime('now', new DateTimeZone($tz ?: 'UTC')); }

function eff(array $a): string { return $a['new_deadline'] ?: $a['deadline']; }

// 1. Retry pending messages
foreach (DB::all("SELECT id FROM outbox WHERE status = 'pending' AND created_at > (UTC_TIMESTAMP() - INTERVAL 3 DAY) ORDER BY id LIMIT 200") as $row) {
    if (Notifier::deliver((int)$row['id'])) $stats['outbox']++;
}

// 2. Personal reminders that are due
foreach (DB::all("SELECT r.*, u.name, u.email, u.phone, o.name AS org_name, a.title AS action_title
                    FROM reminders r JOIN users u ON u.id = r.user_id JOIN organizations o ON o.id = r.org_id LEFT JOIN actions a ON a.id = r.action_id
                   WHERE r.fired_at IS NULL AND r.remind_at <= UTC_TIMESTAMP() AND u.status = 'active' LIMIT 500") as $r) {
    DB::update('reminders', ['fired_at' => now_utc()], ['id' => $r['id']]);
    $link = $r['action_id'] ? 'actions/' . $r['action_id'] : 'dashboard';
    Notifier::inApp((int)$r['user_id'], 'Reminder: ' . $r['body'], $link);
    if ($r['via_email']) {
        $html = Notifier::layout('Reminder', '<p style="font-size:16px"><b>' . e($r['body']) . '</b></p>' . ($r['action_title'] ? '<p>About: ' . e($r['action_title']) . '</p>' : ''), 'Open', url($link), $r['org_name']);
        Notifier::email((int)$r['org_id'], $r['email'], $r['name'], 'Reminder: ' . $r['body'], $html);
    }
    if ($r['via_whatsapp'] && $r['phone']) Notifier::whatsapp((int)$r['org_id'], (string)$r['phone'], $r['name'], 'Reminder: ' . $r['body'] . "\n" . url($link));
    $stats['reminders']++;
}

// 3 & 4. Deadline reminders and overdue alerts (once per day per item, in each company's timezone)
$rows = DB::all("SELECT a.*, o.timezone, o.name AS org_name, ow.name AS owner_name, ow.email AS owner_email, ow.phone AS owner_phone, ow.status AS owner_status,
                        cr.name AS creator_name, cr.email AS creator_email, cr.status AS creator_status,
                        cs.reminder_days, cs.overdue_alerts, os.wa_reminders AS owner_wa
                   FROM actions a JOIN organizations o ON o.id = a.org_id
                   JOIN users ow ON ow.id = a.owner_id JOIN users cr ON cr.id = a.created_by
                   LEFT JOIN user_settings cs ON cs.user_id = a.created_by LEFT JOIN user_settings os ON os.user_id = a.owner_id
                  WHERE a.status = 'open'");
foreach ($rows as $a) {
    $today = local_now($a['timezone'])->format('Y-m-d');
    $due = eff($a);
    $daysLeft = (int)round((strtotime($due . ' UTC') - strtotime($today . ' UTC')) / 86400);
    $days = (int)($a['reminder_days'] ?? 1);

    if ($days > 0 && $daysLeft === $days && $a['last_due_reminder_on'] !== $today && $a['owner_status'] === 'active') {
        $html = Notifier::layout('Due ' . ($days === 1 ? 'tomorrow' : "in $days days") . ': ' . $a['title'], '<p>Hi ' . e(explode(' ', $a['owner_name'])[0]) . ',</p><p><b>' . e($a['title']) . '</b> is due on <b>' . e(fmt_date($due)) . '</b>.</p><p>Mark it complete or add a progress note when you can.</p>', 'Open item', url('actions/' . $a['id']), $a['org_name']);
        Notifier::email((int)$a['org_id'], $a['owner_email'], $a['owner_name'], 'Due ' . ($days === 1 ? 'tomorrow' : "in $days days") . ': ' . $a['title'], $html);
        if ($a['owner_wa'] && $a['owner_phone']) Notifier::whatsapp((int)$a['org_id'], (string)$a['owner_phone'], $a['owner_name'], '"' . $a['title'] . '" is due ' . fmt_date($due) . '. ' . url('actions/' . $a['id']));
        Notifier::inApp((int)$a['owner_id'], '"' . $a['title'] . '" is due ' . ($days === 1 ? 'tomorrow' : "in $days days"), 'actions/' . $a['id']);
        DB::update('actions', ['last_due_reminder_on' => $today], ['id' => $a['id']]);
        $stats['due']++;
    }

    if ($daysLeft < 0 && !$a['last_overdue_alert_on'] && (int)$a['created_by'] !== (int)$a['owner_id'] && $a['overdue_alerts'] && $a['creator_status'] === 'active') {
        $html = Notifier::layout('Overdue: ' . $a['title'], '<p><b>' . e($a['title']) . '</b>, assigned to ' . e($a['owner_name']) . ', was due on ' . e(fmt_date($due)) . ' and isn’t complete yet.</p>', 'Follow up', url('actions/' . $a['id']), $a['org_name']);
        Notifier::email((int)$a['org_id'], $a['creator_email'], $a['creator_name'], 'Overdue: ' . $a['title'], $html);
        Notifier::inApp((int)$a['created_by'], '"' . $a['title'] . '" (' . $a['owner_name'] . ') is overdue', 'actions/' . $a['id']);
        DB::update('actions', ['last_overdue_alert_on' => $today], ['id' => $a['id']]);
        $stats['overdue']++;
    }
}

// 5. Daily digest
foreach (DB::all("SELECT u.id, u.name, u.email, u.org_id, o.name AS org_name, COALESCE(s.timezone, o.timezone) AS tz, s.digest_time, s.last_digest_on
                    FROM users u JOIN organizations o ON o.id = u.org_id JOIN user_settings s ON s.user_id = u.id
                   WHERE u.status = 'active' AND s.digest = 1") as $u) {
    $now = local_now($u['tz']);
    $today = $now->format('Y-m-d');
    if ($u['last_digest_on'] === $today || $now->format('H:i') < $u['digest_time']) continue;
    DB::update('user_settings', ['last_digest_on' => $today], ['user_id' => $u['id']]);
    $items = DB::all("SELECT a.*, m.title AS meeting_title FROM actions a LEFT JOIN meetings m ON m.id = a.meeting_id WHERE a.owner_id = ? AND a.status = 'open' ORDER BY COALESCE(a.new_deadline, a.deadline) LIMIT 30", [$u['id']]);
    $meetings = DB::all("SELECT m.* FROM meetings m JOIN meeting_invitees i ON i.meeting_id = m.id AND i.user_id = ? WHERE m.status = 'scheduled' AND m.meeting_date = ? ORDER BY m.start_time", [$u['id'], $today]);
    if (!$items && !$meetings) continue;
    $body = '<p>Hi ' . e(explode(' ', $u['name'])[0]) . ', here’s your day.</p>';
    if ($meetings) {
        $body .= '<p style="margin-bottom:4px"><b>Meetings today</b></p><ul style="padding-left:18px;margin-top:0">';
        foreach ($meetings as $m) $body .= '<li>' . e(fmt_time($m['start_time'])) . ' · <a href="' . e(url('meetings/' . $m['id'])) . '">' . e($m['title']) . '</a></li>';
        $body .= '</ul>';
    }
    if ($items) {
        $body .= '<p style="margin-bottom:4px"><b>Your open items (' . count($items) . ')</b></p><ul style="padding-left:18px;margin-top:0">';
        foreach ($items as $a) {
            $d = eff($a);
            $late = $d < $today;
            $body .= '<li><a href="' . e(url('actions/' . $a['id'])) . '">' . e($a['title']) . '</a> <span style="color:' . ($late ? '#B42318' : '#7B8794') . '">· ' . ($late ? 'overdue since ' : 'due ') . e(fmt_date($d, false)) . '</span></li>';
        }
        $body .= '</ul>';
    }
    Notifier::email((int)$u['org_id'], $u['email'], $u['name'], 'Your day: ' . count($items) . ' open item' . (count($items) === 1 ? '' : 's') . ($meetings ? ', ' . count($meetings) . ' meeting' . (count($meetings) === 1 ? '' : 's') : ''), Notifier::layout('Your daily digest', $body, 'Open dashboard', url('dashboard'), $u['org_name']));
    $stats['digests']++;
}

// 6. Housekeeping
DB::run('DELETE FROM login_attempts WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
DB::run('DELETE FROM auth_tokens WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)');
DB::run("DELETE FROM outbox WHERE status = 'sent' AND sent_at < (UTC_TIMESTAMP() - INTERVAL 60 DAY)");

echo '[' . gmdate('c') . '] ' . json_encode($stats) . PHP_EOL;
flock($lock, LOCK_UN);
