<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Access;
use App\Services\Actions;
use App\Services\Audit;
use App\Services\GoogleCalendar;
use App\Services\Ics;
use App\Services\Integrations;
use App\Services\Meetings;
use App\Services\MicrosoftGraph;
use App\Services\Notifier;

final class MeetingController
{
    public function index(): void
    {
        $tab = ($_GET['tab'] ?? '') === 'past' ? 'past' : 'upcoming';
        $today = today();
        $visible = is_admin()
            ? ['m.org_id = :org', ['org' => org_id()]]
            : ['m.org_id = :org AND (m.organizer_id = :me OR EXISTS (SELECT 1 FROM meeting_invitees i WHERE i.meeting_id = m.id AND i.user_id = :me2))', ['org' => org_id(), 'me' => uid(), 'me2' => uid()]];
        $sql = "SELECT m.*, u.name AS organizer_name,
                  (SELECT COUNT(*) FROM meeting_invitees i WHERE i.meeting_id = m.id) AS invitee_count,
                  (SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = m.id) AS point_count,
                  (SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = m.id AND (ai.discussion IS NULL OR ai.discussion = '')) AS blank_points,
                  (SELECT COUNT(*) FROM actions a WHERE a.meeting_id = m.id) AS action_count,
                  (SELECT COUNT(*) FROM actions a WHERE a.meeting_id = m.id AND a.status = 'open') AS open_count
                FROM meetings m JOIN users u ON u.id = m.organizer_id WHERE {$visible[0]}";
        $up = DB::all("$sql AND m.status = 'scheduled' AND m.meeting_date >= :today ORDER BY m.meeting_date, m.start_time", $visible[1] + ['today' => $today]);
        $past = DB::all("$sql AND NOT (m.status = 'scheduled' AND m.meeting_date >= :today) ORDER BY m.meeting_date DESC, m.start_time DESC LIMIT 200", $visible[1] + ['today' => $today]);
        $plan = is_admin() && isset($_GET['plan']);
        $members = is_admin() ? Access::members() : [];
        $settings = DB::one('SELECT * FROM user_settings WHERE user_id = ?', [uid()]) ?: [];
        $ints = Integrations::all(org_id());
        echo view('meetings/index', compact('tab', 'up', 'past', 'plan', 'members', 'settings', 'ints') + ['title' => 'Meetings']);
    }

    public function store(): void
    {
        $title = (string)input('title');
        $date = (string)input('date');
        $time = (string)input('time');
        $duration = max(5, min(600, (int)input('duration', 30)));
        $platform = in_array(input('platform'), ['meet', 'teams', 'inperson'], true) ? input('platform') : 'meet';
        $location = (string)input('location');
        $agenda = json_decode((string)($_POST['agenda_json'] ?? '[]'), true);
        $inviteeIds = array_map('intval', (array)($_POST['invitees'] ?? []));
        $newPeople = json_decode((string)($_POST['new_people_json'] ?? '[]'), true) ?: [];
        $viaEmail = !empty($_POST['via_email']);
        $viaWa = !empty($_POST['via_whatsapp']) && Integrations::connected(org_id(), 'whatsapp');

        $errors = [];
        if ($title === '') $errors[] = 'Give the meeting a title.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) $errors[] = 'Pick a date and start time.';
        $agenda = array_values(array_filter(is_array($agenda) ? $agenda : [], fn($p) => is_array($p) && trim((string)($p['title'] ?? '')) !== ''));
        if (!$agenda) $errors[] = 'Add at least one agenda point.';
        if (!$viaEmail && !$viaWa) $errors[] = 'Choose email or WhatsApp to send the invite.';
        if ($platform === 'meet' && !Integrations::connected(org_id(), 'google')) $errors[] = 'Google Meet isn’t connected. Connect it in Integrations or choose another platform.';
        if ($platform === 'teams' && !Integrations::connected(org_id(), 'microsoft')) $errors[] = 'Microsoft Teams isn’t connected. Connect it in Integrations or choose another platform.';
        $members = Access::members();
        $inviteeIds = array_values(array_unique(array_filter($inviteeIds, fn($id) => isset($members[$id]))));
        foreach ($newPeople as $np) {
            if (!filter_var($np['email'] ?? '', FILTER_VALIDATE_EMAIL) || trim((string)($np['name'] ?? '')) === '') { $errors[] = 'Each new invitee needs a name and a valid email.'; break; }
        }
        if (count($inviteeIds) + count($newPeople) === 0) $errors[] = 'Add at least one invitee.';
        if ($errors) {
            $_SESSION['_planner'] = $_POST;
            foreach ($errors as $e) flash('error', $e);
            redirect('meetings?plan=1');
        }

        // Invite new people as participants (registration is required to take part)
        foreach ($newPeople as $np) {
            $email = mb_strtolower(trim($np['email']));
            $existing = DB::one('SELECT id, org_id FROM users WHERE email = ?', [$email]);
            if ($existing) {
                if ((int)$existing['org_id'] !== org_id()) { flash('error', "$email already uses " . config('app.name') . ' with another company, so they can’t be invited here.'); continue; }
                $inviteeIds[] = (int)$existing['id'];
                continue;
            }
            $newId = DB::insert('users', ['org_id' => org_id(), 'name' => mb_substr(trim($np['name']), 0, 120), 'email' => $email, 'phone' => trim((string)($np['phone'] ?? '')) ?: null,
                'role' => 'participant', 'status' => 'invited', 'invited_by' => uid(), 'created_at' => now_utc()]);
            DB::insert('user_settings', ['user_id' => $newId]);
            AuthController::sendInvite($newId, user()['name'], user()['org_name']);
            $inviteeIds[] = $newId;
        }
        $inviteeIds = array_values(array_unique(array_merge([uid()], $inviteeIds)));

        $tz = user_tz();
        $meetingId = DB::transaction(function () use ($title, $date, $time, $duration, $platform, $location, $tz, $viaEmail, $viaWa, $agenda, $inviteeIds) {
            $id = DB::insert('meetings', [
                'org_id' => org_id(), 'organizer_id' => uid(), 'title' => mb_substr($title, 0, 200), 'meeting_date' => $date, 'start_time' => $time,
                'duration_min' => (int)$duration, 'timezone' => $tz, 'starts_at_utc' => local_to_utc($date, $time, $tz), 'platform' => $platform,
                'location' => $platform === 'inperson' ? mb_substr($location, 0, 250) : null, 'status' => 'scheduled',
                'sent_email' => $viaEmail ? 1 : 0, 'sent_whatsapp' => $viaWa ? 1 : 0, 'created_at' => now_utc(),
            ]);
            foreach ($inviteeIds as $u) DB::insert('meeting_invitees', ['meeting_id' => $id, 'user_id' => $u, 'rsvp' => $u === uid() ? 'yes' : 'pending']);
            foreach ($agenda as $i => $p) {
                $presenter = (int)($p['presenter'] ?? 0);
                DB::insert('agenda_items', [
                    'meeting_id' => $id, 'position' => $i, 'title' => mb_substr(trim((string)$p['title']), 0, 250),
                    'details' => trim((string)($p['details'] ?? '')) ?: null,
                    'presenter_id' => in_array($presenter, $inviteeIds, true) ? $presenter : null,
                    'minutes_allotted' => max(0, min(600, (int)($p['mins'] ?? 0))),
                    'subpoints' => json_encode(array_values(array_filter(array_map(fn($s) => mb_substr(trim((string)$s), 0, 250), (array)($p['subs'] ?? [])), 'strlen'))),
                ]);
            }
            return $id;
        });
        unset($_SESSION['_planner']);
        Audit::log('meeting.created', 'meeting', $meetingId);
        $m = DB::one('SELECT * FROM meetings WHERE id = ?', [$meetingId]);
        foreach (Meetings::sendInvites($m) as $w) flash('error', $w);
        flash('success', 'Meeting scheduled and invites sent to ' . plural(count($inviteeIds) - 1, 'person', 'people') . '.');
        redirect('meetings/' . $meetingId);
    }

    public function show(int $id): void
    {
        $m = Access::meeting($id);
        $agenda = Meetings::agenda($id);
        $invitees = Meetings::invitees($id);
        $actions = DB::all('SELECT a.*, u.name AS owner_name FROM actions a JOIN users u ON u.id = a.owner_id WHERE a.meeting_id = ? ORDER BY a.id', [$id]);
        $byPoint = [];
        foreach ($actions as $a) $byPoint[(int)$a['agenda_item_id']][] = $a;
        $canManage = Access::canManageMeeting($m);
        $isInvitee = in_array(uid(), array_map('intval', array_column($invitees, 'id')), true);
        $myRsvp = null;
        foreach ($invitees as $i) if ((int)$i['id'] === uid()) $myRsvp = $i['rsvp'];
        echo view('meetings/show', compact('m', 'agenda', 'invitees', 'byPoint', 'canManage', 'isInvitee', 'myRsvp') + ['title' => $m['title']]);
    }

    public function complete(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m)) abort(403);
        DB::update('meetings', ['status' => 'completed', 'completed_at' => now_utc(), 'updated_at' => now_utc()], ['id' => $id]);
        foreach (Meetings::invitees($id) as $i) if ((int)$i['id'] !== uid()) Notifier::inApp((int)$i['id'], '"' . $m['title'] . '" is over. You can add minutes now.', 'meetings/' . $id);
        flash('success', 'Minutes are open. Invitees can add minutes too.');
        redirect('meetings/' . $id);
    }

    public function cancel(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m) || $m['status'] !== 'scheduled') abort(403);
        DB::update('meetings', ['status' => 'cancelled', 'updated_at' => now_utc()], ['id' => $id]);
        if ($m['external_event_id']) {
            $m['platform'] === 'meet' ? GoogleCalendar::cancelEvent(org_id(), $m['external_event_id']) : MicrosoftGraph::cancelEvent(org_id(), $m['external_event_id']);
        }
        $invitees = Meetings::invitees($id);
        $organizer = ['name' => $m['organizer_name'], 'email' => $m['organizer_email']];
        $ics = Ics::invite($m, $organizer, $invitees, 'Cancelled', 'CANCEL');
        foreach ($invitees as $i) {
            if ((int)$i['id'] === uid()) continue;
            $html = Notifier::layout('Cancelled: ' . $m['title'], '<p>' . e(user()['name']) . ' cancelled <b>' . e($m['title']) . '</b> planned for ' . e(fmt_date($m['meeting_date'])) . ', ' . e(fmt_time($m['start_time'])) . '.</p>', null, null, user()['org_name']);
            Notifier::email(org_id(), $i['email'], $i['name'], 'Cancelled: ' . $m['title'], $html, $m['external_event_id'] ? [] : [['name' => 'cancel.ics', 'mime' => 'text/calendar; method=CANCEL; charset=UTF-8', 'content' => $ics]]);
            Notifier::inApp((int)$i['id'], '"' . $m['title'] . '" was cancelled', 'meetings/' . $id);
        }
        Audit::log('meeting.cancelled', 'meeting', $id);
        flash('success', 'Meeting cancelled and invitees notified.');
        redirect('meetings/' . $id);
    }

    public function resend(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m)) abort(403);
        foreach (Meetings::sendInvites($m, false) as $w) flash('error', $w);
        flash('success', 'Invite sent again to all invitees.');
        redirect('meetings/' . $id);
    }

    /** Invitees save what was discussed; managers also set attendance. */
    public function saveMinutes(int $id): void
    {
        $m = Access::meeting($id);
        $canManage = Access::canManageMeeting($m);
        $isInvitee = (bool)DB::value('SELECT 1 FROM meeting_invitees WHERE meeting_id = ? AND user_id = ?', [$id, uid()]);
        if (!$canManage && !$isInvitee) abort(403);
        if ($m['status'] !== 'completed') { flash('error', 'Minutes open once the meeting is marked as over.'); redirect('meetings/' . $id); }
        foreach ((array)($_POST['discussion'] ?? []) as $pointId => $text) {
            DB::run('UPDATE agenda_items SET discussion = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND meeting_id = ?', [mb_substr(trim((string)$text), 0, 20000), uid(), (int)$pointId, $id]);
        }
        if ($canManage && isset($_POST['attendance_submitted'])) {
            $present = array_map('intval', (array)($_POST['attended'] ?? []));
            foreach (Meetings::invitees($id) as $i) DB::run('UPDATE meeting_invitees SET attended = ? WHERE meeting_id = ? AND user_id = ?', [in_array((int)$i['id'], $present, true) ? 1 : 0, $id, $i['id']]);
        }
        DB::update('meetings', ['updated_at' => now_utc()], ['id' => $id]);
        flash('success', 'Minutes saved.');
        redirect('meetings/' . $id);
    }

    public function addPoint(int $id): void
    {
        $m = Access::meeting($id);
        $title = (string)input('title');
        if ($title === '') redirect('meetings/' . $id);
        $pos = (int)DB::value('SELECT COALESCE(MAX(position), 0) + 1 FROM agenda_items WHERE meeting_id = ?', [$id]);
        DB::insert('agenda_items', ['meeting_id' => $id, 'position' => $pos, 'title' => mb_substr($title, 0, 250), 'subpoints' => '[]', 'added_in_meeting' => 1, 'updated_by' => uid(), 'updated_at' => now_utc()]);
        flash('success', 'Point added to the minutes.');
        redirect('meetings/' . $id . '#points');
    }

    public function addAction(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m)) abort(403, 'Only admins or the organiser can assign action items.');
        $pointId = (int)input('agenda_item_id');
        if (!DB::value('SELECT 1 FROM agenda_items WHERE id = ? AND meeting_id = ?', [$pointId, $id])) abort(404);
        // Keep any minutes typed on the page before the action was added
        foreach ((array)($_POST['discussion'] ?? []) as $pid => $text) {
            DB::run('UPDATE agenda_items SET discussion = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND meeting_id = ?', [mb_substr(trim((string)$text), 0, 20000), uid(), (int)$pid, $id]);
        }
        $owner = (int)input('owner_id');
        $inv = array_map('intval', array_column(Meetings::invitees($id), 'id'));
        $title = (string)input('title');
        $deadline = (string)input('deadline');
        if ($title === '' || !in_array($owner, $inv, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) { flash('error', 'Add a description, a responsible person from the invitees and a deadline.'); redirect('meetings/' . $id . '#point-' . $pointId); }
        $priority = in_array(input('priority'), ['high', 'medium', 'low'], true) ? input('priority') : 'medium';
        $ownerRow = Access::member($owner);
        Actions::create(['meeting_id' => $id, 'agenda_item_id' => $pointId, 'title' => $title, 'priority' => $priority, 'owner_id' => $owner, 'owner_name' => $ownerRow['name'], 'deadline' => $deadline, 'quiet' => true]);
        flash('success', 'Action item added.');
        redirect('meetings/' . $id . '#point-' . $pointId);
    }

    public function rsvp(int $id): void
    {
        Access::meeting($id);
        $r = in_array(input('rsvp'), ['yes', 'no', 'maybe'], true) ? input('rsvp') : 'pending';
        DB::run('UPDATE meeting_invitees SET rsvp = ? WHERE meeting_id = ? AND user_id = ?', [$r, $id, uid()]);
        flash('success', 'Response saved.');
        redirect('meetings/' . $id);
    }

    public function mom(int $id): void
    {
        $m = Access::meeting($id);
        echo view('meetings/mom', self::momData($m) + ['title' => 'Minutes: ' . $m['title']]);
    }

    public function sendMom(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m)) abort(403);
        $d = self::momData($m);
        $body = \App\Core\View::partial('meetings/_mom_email', $d);
        $count = 0;
        foreach ($d['invitees'] as $i) {
            Notifier::email(org_id(), $i['email'], $i['name'], 'Minutes: ' . $m['title'] . ' (' . fmt_date($m['meeting_date'], false) . ')', Notifier::layout('Minutes of meeting: ' . $m['title'], $body, 'Open in ' . config('app.name'), url('meetings/' . $id . '/mom'), user()['org_name']));
            if ($m['sent_whatsapp'] && $i['phone']) Notifier::whatsapp(org_id(), (string)$i['phone'], $i['name'], "Minutes of \"{$m['title']}\" are ready: " . url('meetings/' . $id . '/mom'));
            $count++;
        }
        DB::update('meetings', ['mom_sent_at' => now_utc()], ['id' => $id]);
        Audit::log('meeting.mom_sent', 'meeting', $id);
        flash('success', 'Minutes sent to ' . plural($count, 'person', 'people') . '.');
        redirect('meetings/' . $id . '/mom');
    }

    public function printInvite(int $id): void
    {
        $m = Access::meeting($id);
        echo view('meetings/print-invite', ['m' => $m, 'agenda' => Meetings::agenda($id), 'invitees' => Meetings::invitees($id), 'title' => 'Invitation: ' . $m['title']], 'layouts/print');
    }

    public function printMom(int $id): void
    {
        $m = Access::meeting($id);
        echo view('meetings/_mom_sheet', self::momData($m) + ['title' => 'Minutes: ' . $m['title'], 'print' => true], 'layouts/print');
    }

    public function ics(int $id): void
    {
        $m = Access::meeting($id);
        $ics = Ics::invite($m, ['name' => $m['organizer_name'], 'email' => $m['organizer_email']], Meetings::invitees($id), Meetings::agendaText(Meetings::agenda($id)));
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="meeting-' . $id . '.ics"');
        echo $ics;
        exit;
    }

    private static function momData(array $m): array
    {
        $id = (int)$m['id'];
        $agenda = Meetings::agenda($id);
        $invitees = Meetings::invitees($id);
        $actions = DB::all('SELECT a.*, u.name AS owner_name FROM actions a JOIN users u ON u.id = a.owner_id WHERE a.meeting_id = ? ORDER BY a.agenda_item_id, a.id', [$id]);
        $ordered = [];
        foreach ($agenda as $p) foreach ($actions as $a) if ((int)$a['agenda_item_id'] === (int)$p['id']) $ordered[] = $a;
        $signature = (string)DB::value('SELECT signature FROM user_settings WHERE user_id = ?', [$m['organizer_id']]);
        return ['m' => $m, 'agenda' => $agenda, 'invitees' => $invitees, 'actions' => $ordered, 'canManage' => Access::canManageMeeting($m), 'signature' => $signature];
    }
}
