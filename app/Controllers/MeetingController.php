<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\View;
use App\Services\Access;
use App\Services\Actions;
use App\Services\Audit;
use App\Services\GoogleCalendar;
use App\Services\Ics;
use App\Services\Integrations;
use App\Services\Meetings;
use App\Services\MicrosoftGraph;
use App\Services\Notifier;
use App\Services\Translator;

/**
 * Meetings: list and planner on one page, the meeting page (agenda, requests, minutes), minutes of meeting and printing.
 * Anyone can plan a meeting (participants invite existing teammates only). The organiser is the host.
 */
final class MeetingController
{
    public function index(): void
    {
        $tab = ($_GET['tab'] ?? '') === 'past' ? 'past' : 'upcoming';
        $today = today();
        $projects = Access::projects(true);
        $projectId = (int)($_GET['project'] ?? 0);
        if ($projectId && !isset($projects[$projectId])) $projectId = 0;

        $where = 'm.org_id = :org';
        $params = ['org' => org_id()];
        if (!is_admin()) {
            $where .= ' AND (m.organizer_id = :me OR EXISTS (SELECT 1 FROM meeting_invitees i WHERE i.meeting_id = m.id AND i.user_id = :me2))';
            $params += ['me' => uid(), 'me2' => uid()];
        }
        if ($projectId) { $where .= ' AND m.project_id = :proj'; $params['proj'] = $projectId; }
        $sql = "SELECT m.*, u.name AS organizer_name, p.name AS project_name,
                  (SELECT COUNT(*) FROM meeting_invitees i WHERE i.meeting_id = m.id) AS invitee_count,
                  (SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = m.id AND " . Meetings::ON_AGENDA . ") AS point_count,
                  (SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = m.id AND " . Meetings::ON_AGENDA . " AND (ai.discussion IS NULL OR ai.discussion = '')) AS blank_points,
                  (SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = m.id AND ai.request_status = 'pending') AS pending_requests,
                  (SELECT COUNT(*) FROM actions a WHERE a.meeting_id = m.id) AS action_count,
                  (SELECT COUNT(*) FROM actions a WHERE a.meeting_id = m.id AND a.status <> 'done') AS open_count
                FROM meetings m JOIN users u ON u.id = m.organizer_id LEFT JOIN projects p ON p.id = m.project_id WHERE $where";
        $up = DB::all("$sql AND m.status = 'scheduled' AND m.meeting_date >= :today ORDER BY m.meeting_date, m.start_time", $params + ['today' => $today]);
        $past = DB::all("$sql AND NOT (m.status = 'scheduled' AND m.meeting_date >= :today) ORDER BY m.meeting_date DESC, m.start_time DESC LIMIT 200", $params + ['today' => $today]);

        $plan = isset($_GET['plan']);
        $members = Access::members();
        $settings = DB::one('SELECT * FROM user_settings WHERE user_id = ?', [uid()]) ?: [];
        $ints = Integrations::all(org_id());
        $activeProjects = array_filter($projects, fn($p) => $p['status'] === 'active');
        echo view('meetings/index', compact('tab', 'up', 'past', 'plan', 'members', 'settings', 'ints', 'projects', 'activeProjects', 'projectId') + ['title' => 'Meetings']);
    }

    public function store(): void
    {
        $title = trim((string)input('title'));
        $date = (string)input('date');
        $time = (string)input('time');
        $endTime = (string)input('end_time');
        if ($endTime === '' && isset($_POST['duration']) && valid_time($time)) $endTime = min_to_time(time_to_min($time) + max(5, min(600, (int)input('duration', 30)))); // a page cached from before From/To still posts a duration
        $repeat = (string)input('repeat', 'none');
        $repeatUntil = (string)input('repeat_until', '');
        $platform = in_array(input('platform'), ['meet', 'teams', 'inperson'], true) ? input('platform') : 'meet';
        $location = (string)input('location');
        $note = mb_substr(trim((string)input('personal_note', '')), 0, 2000);
        $agenda = json_decode((string)($_POST['agenda_json'] ?? '[]'), true);
        $inviteeIds = array_map('intval', (array)($_POST['invitees'] ?? []));
        $newPeople = json_decode((string)($_POST['new_people_json'] ?? '[]'), true) ?: [];
        $viaEmail = !empty($_POST['via_email']);
        $viaWa = !empty($_POST['via_whatsapp']) && Integrations::connected(org_id(), 'whatsapp');
        $viaPersonal = !empty($_POST['via_personal']);

        $errors = [];
        if ($title === '') $errors[] = 'Give the meeting a title.';
        $dates = [$date];
        $duration = 30;
        if (!valid_date($date) || !valid_time($time)) $errors[] = 'Pick a date and a From time.';
        elseif (!valid_time($endTime)) $errors[] = 'Pick a To time.';
        elseif (time_to_min($endTime) <= time_to_min($time)) $errors[] = 'The To time has to be later than the From time.';
        else $duration = time_to_min($endTime) - time_to_min($time);
        if ($repeat !== 'none' && $repeat !== '' && valid_date($date)) {
            if ($problem = Meetings::repeatProblem($repeat, $date)) $errors[] = $problem;
            elseif (!valid_date($repeatUntil) || $repeatUntil <= $date) $errors[] = 'Pick the date the repeat ends. It has to be after the first meeting.';
            else {
                $dates = Meetings::occurrences($date, $repeat, $repeatUntil);
                if (count($dates) > Meetings::MAX_OCCURRENCES) $errors[] = 'That repeats more than ' . Meetings::MAX_OCCURRENCES . ' times. Choose an earlier end date.';
                elseif (count($dates) < 2) $errors[] = 'With that end date the meeting would not repeat. Pick a later end date or choose “Does not repeat”.';
            }
        } else {
            $repeat = 'none';
        }
        $agenda = array_values(array_filter(is_array($agenda) ? $agenda : [], fn($p) => is_array($p) && trim((string)($p['title'] ?? '')) !== ''));
        if (!$agenda) $errors[] = 'Add at least one agenda point.';
        if (!$viaEmail && !$viaWa && !$viaPersonal) $errors[] = 'Choose how to send the invite: email, WhatsApp or personal invite.';
        if ($platform === 'meet' && !Integrations::connected(org_id(), 'google')) $errors[] = 'Google Meet isn’t connected. Connect it in Integrations or choose another platform.';
        if ($platform === 'teams' && !Integrations::connected(org_id(), 'microsoft')) $errors[] = 'Microsoft Teams isn’t connected. Connect it in Integrations or choose another platform.';

        // Project: an existing active project, a new one typed in the planner, or none
        $projectChoice = (int)input('project_id', 0);
        $newProject = mb_substr(trim((string)input('new_project', '')), 0, 120);
        $projectId = null;
        if ($projectChoice === -1) {
            if (mb_strlen($newProject) < 2) $errors[] = 'Give the new project a name.';
        } elseif ($projectChoice > 0) {
            $p = Access::project($projectChoice);
            if (!$p || $p['status'] !== 'active') $errors[] = 'That project isn’t available. Pick another one.';
            else $projectId = (int)$p['id'];
        }

        $members = Access::members();
        $inviteeIds = array_values(array_unique(array_filter($inviteeIds, fn($id) => isset($members[$id]) && $id !== uid())));
        if ($newPeople && !is_admin()) { $errors[] = 'Only admins can invite new people. Pick teammates from the list, or ask an admin to invite them first.'; $newPeople = []; }
        foreach ($newPeople as $np) {
            if (!filter_var($np['email'] ?? '', FILTER_VALIDATE_EMAIL) || trim((string)($np['name'] ?? '')) === '') { $errors[] = 'Each new invitee needs a name and a valid email.'; break; }
        }
        if (count($inviteeIds) + count($newPeople) === 0) $errors[] = 'Add at least one invitee.';
        if ($errors) {
            $_SESSION['_planner'] = $_POST;
            foreach ($errors as $e) flash('error', $e);
            redirect('meetings?plan=1');
        }

        if ($projectChoice === -1) {
            $existing = DB::one('SELECT id, status FROM projects WHERE org_id = ? AND name = ?', [org_id(), $newProject]);
            if ($existing) {
                $projectId = (int)$existing['id'];
                if ($existing['status'] !== 'active') DB::update('projects', ['status' => 'active', 'updated_at' => now_utc()], ['id' => $projectId]);
            } else {
                $projectId = DB::insert('projects', ['org_id' => org_id(), 'name' => $newProject, 'created_by' => uid(), 'created_at' => now_utc()]);
                Audit::log('project.created', 'project', $projectId);
            }
        }

        // Invite new people as participants (registration is required to take part). Admins only.
        foreach ($newPeople as $np) {
            $email = mb_strtolower(trim($np['email']));
            $existing = DB::one('SELECT id, org_id FROM users WHERE email = ?', [$email]);
            if ($existing) {
                if ((int)$existing['org_id'] !== org_id()) { flash('error', "$email already uses " . config('app.name') . ' with another company, so they can’t be invited here.'); continue; }
                $inviteeIds[] = (int)$existing['id'];
                continue;
            }
            $seats = DB::value('SELECT seats_limit FROM organizations WHERE id = ?', [org_id()]);
            if ($seats && (int)DB::value("SELECT COUNT(*) FROM users WHERE org_id = ? AND status <> 'disabled'", [org_id()]) >= (int)$seats) {
                flash('error', "Your plan’s seat limit is reached, so $email wasn’t invited.");
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
        // One meeting per date. A repeating meeting is a series: every date has its own minutes and tasks, and shares the agenda and invitees.
        $meetingId = DB::transaction(function () use ($title, $dates, $time, $endTime, $duration, $platform, $location, $tz, $viaEmail, $viaWa, $viaPersonal, $note, $projectId, $agenda, $inviteeIds, $repeat) {
            $firstId = 0;
            foreach ($dates as $d) {
                $id = DB::insert('meetings', [
                    'org_id' => org_id(), 'project_id' => $projectId, 'organizer_id' => uid(), 'title' => mb_substr($title, 0, 200), 'meeting_date' => $d, 'start_time' => $time,
                    'end_time' => $endTime, 'duration_min' => (int)$duration, 'timezone' => $tz, 'starts_at_utc' => local_to_utc($d, $time, $tz), 'platform' => $platform,
                    'location' => $platform === 'inperson' ? mb_substr($location, 0, 250) : null, 'status' => 'scheduled',
                    'sent_email' => $viaEmail ? 1 : 0, 'sent_whatsapp' => $viaWa ? 1 : 0, 'sent_personal' => $viaPersonal ? 1 : 0,
                    'personal_note' => $note !== '' ? $note : null, 'series_id' => $firstId ?: null, 'recurrence' => $repeat !== 'none' ? $repeat : null, 'created_at' => now_utc(),
                ]);
                if (!$firstId) {
                    $firstId = $id;
                    if ($repeat !== 'none') DB::update('meetings', ['series_id' => $id], ['id' => $id]);
                }
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
            }
            return $firstId;
        });
        unset($_SESSION['_planner']);
        Audit::log('meeting.created', 'meeting', $meetingId, $repeat !== 'none' ? ['repeat' => $repeat, 'meetings' => count($dates)] : []);
        $m = DB::one('SELECT * FROM meetings WHERE id = ?', [$meetingId]);
        foreach (Meetings::sendInvites($m) as $w) flash('error', $w);
        if ($repeat !== 'none') { // every date in the series uses the same meeting link
            $m = DB::one('SELECT join_url FROM meetings WHERE id = ?', [$meetingId]);
            if (!empty($m['join_url'])) DB::run('UPDATE meetings SET join_url = ? WHERE series_id = ? AND id <> ?', [$m['join_url'], $meetingId, $meetingId]);
        }
        $n = plural(count($inviteeIds) - 1, 'person', 'people');
        $what = $repeat !== 'none' ? count($dates) . ' meetings scheduled (' . Meetings::repeatPhrase($repeat) . ', until ' . fmt_date(end($dates)) . ')' : 'Meeting scheduled';
        if (!$viaEmail && !$viaWa) flash('success', $what . '. Hand out the personal invitations to ' . $n . ': print the letters or share the invite from this page.');
        else flash('success', $what . ' and ' . ($repeat !== 'none' ? 'one invite for the whole series' : 'invites') . ' sent to ' . $n . '.' . ($viaPersonal ? ' Personal invitation letters are ready to print on this page.' : ''));
        redirect('meetings/' . $meetingId);
    }

    public function show(int $id): void
    {
        $m = Access::meeting($id);
        $agenda = Meetings::agenda($id);
        $requests = Meetings::requests($id);
        $invitees = Meetings::invitees($id);
        $actions = DB::all('SELECT a.*, u.name AS owner_name FROM actions a JOIN users u ON u.id = a.owner_id WHERE a.meeting_id = ? ORDER BY a.id', [$id]);
        $byPoint = [];
        foreach ($actions as $a) $byPoint[(int)$a['agenda_item_id']][] = $a;
        $canManage = Access::canManageMeeting($m);
        $isHost = Access::isHost($m);
        $isInvitee = in_array(uid(), array_map('intval', array_column($invitees, 'id')), true);
        $myRsvp = null;
        foreach ($invitees as $i) if ((int)$i['id'] === uid()) $myRsvp = $i['rsvp'];
        $project = Meetings::project($m);
        $series = Meetings::series($m);
        $carried = Meetings::carriedForward($m);
        $seen = $canManage && $m['status'] === 'completed' && !empty($m['minutes_locked_at']) ? Meetings::seenSummary($m) : null;
        echo view('meetings/show', compact('m', 'agenda', 'requests', 'invitees', 'byPoint', 'canManage', 'isHost', 'isInvitee', 'myRsvp', 'project', 'series', 'carried', 'seen') + ['title' => $m['title']]);
    }

    public function complete(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m)) abort(403, 'Only the host or an admin can mark the meeting as over.');
        if ($m['status'] !== 'scheduled') redirect('meetings/' . $id);
        DB::update('meetings', ['status' => 'completed', 'completed_at' => now_utc(), 'updated_at' => now_utc()], ['id' => $id]);
        foreach (Meetings::invitees($id) as $i) if ((int)$i['id'] !== uid()) Notifier::inApp((int)$i['id'], '"' . $m['title'] . '" is over. You can add minutes now.', 'meetings/' . $id);
        $pending = (int)DB::value("SELECT COUNT(*) FROM agenda_items WHERE meeting_id = ? AND request_status = 'pending'", [$id]);
        flash('success', 'Minutes are open. Invitees can add minutes too.' . ($pending ? ' ' . plural($pending, 'point request') . ' still wait for your decision.' : ''));
        redirect('meetings/' . $id);
    }

    public function cancel(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m) || $m['status'] !== 'scheduled') abort(403);
        $inSeries = !empty($m['series_id']);
        $following = $inSeries && input('scope') === 'following';
        $ids = [$id];
        if ($following) { // this meeting and every later one in the series that is still scheduled
            $ids = array_map('intval', array_column(DB::all("SELECT id FROM meetings WHERE series_id = ? AND org_id = ? AND status = 'scheduled' AND (meeting_date > ? OR (meeting_date = ? AND start_time >= ?)) ORDER BY meeting_date, start_time",
                [$m['series_id'], org_id(), $m['meeting_date'], $m['meeting_date'], $m['start_time']]), 'id'));
            if (!in_array($id, $ids, true)) $ids[] = $id;
        }
        DB::run('UPDATE meetings SET status = \'cancelled\', updated_at = ? WHERE org_id = ? AND id IN (' . implode(',', $ids) . ')', [now_utc(), org_id()]);

        // The calendar event of a series is one event for all dates (it belongs to the first meeting). Only remove it when the whole series goes.
        $master = $inSeries ? (DB::one('SELECT id, platform, external_event_id FROM meetings WHERE id = ?', [$m['series_id']]) ?: $m) : $m;
        $calendarNote = '';
        if ($master['external_event_id']) {
            if (!$inSeries || ($following && (int)$master['id'] === $id)) {
                try {
                    $master['platform'] === 'meet' ? GoogleCalendar::cancelEvent(org_id(), $master['external_event_id']) : MicrosoftGraph::cancelEvent(org_id(), $master['external_event_id']);
                } catch (\Throwable $e) {
                    flash('error', 'The calendar event couldn’t be cancelled: ' . $e->getMessage());
                }
            } else {
                $calendarNote = ' Your ' . platform_label((string)$master['platform']) . ' calendar entry still lists ' . (count($ids) === 1 ? 'that date' : 'those dates') . '. Remove ' . (count($ids) === 1 ? 'it' : 'them') . ' there if needed.';
            }
        }

        $invitees = Meetings::invitees($id);
        $organizer = ['name' => $m['organizer_name'], 'email' => $m['organizer_email']];
        $icsOpts = $inSeries ? ['uid_id' => (int)$m['series_id'], 'recurrence_id' => gmdate('Ymd\THis\Z', strtotime($m['starts_at_utc'] . ' UTC')), 'future' => $following] : [];
        $ics = Ics::invite($m, $organizer, $invitees, 'Cancelled', 'CANCEL', $icsOpts);
        $what = count($ids) > 1 ? 'this meeting and the ' . (count($ids) - 1) . ' after it' : $m['title'];
        $mailLine = $following && count($ids) > 1
            ? '<p>' . e(user()['name']) . ' cancelled <b>' . e($m['title']) . '</b> from ' . e(fmt_date($m['meeting_date'])) . ', ' . e(fmt_range($m)) . ' onwards: ' . e(plural(count($ids), 'meeting')) . ' in the series.</p>'
            : '<p>' . e(user()['name']) . ' cancelled <b>' . e($m['title']) . '</b> planned for ' . e(fmt_date($m['meeting_date'])) . ', ' . e(fmt_range($m)) . '.</p>';
        foreach ($invitees as $i) {
            if ((int)$i['id'] === uid()) continue;
            if ($m['sent_email'] || !$m['sent_whatsapp']) {
                $html = Notifier::layout('Cancelled: ' . $m['title'], $mailLine, null, null, user()['org_name']);
                Notifier::email(org_id(), $i['email'], $i['name'], 'Cancelled: ' . $m['title'] . ' · ' . fmt_date($m['meeting_date'], false), $html, $master['external_event_id'] ? [] : [['name' => 'cancel.ics', 'mime' => 'text/calendar; method=CANCEL; charset=UTF-8', 'content' => $ics]]);
            }
            if ($m['sent_whatsapp'] && $i['phone']) Notifier::whatsapp(org_id(), (string)$i['phone'], $i['name'], 'Cancelled: "' . $m['title'] . '" planned for ' . fmt_date($m['meeting_date']) . ', ' . fmt_range($m) . ($following && count($ids) > 1 ? ' and the ' . (count($ids) - 1) . ' meetings after it.' : '.'));
            Notifier::inApp((int)$i['id'], '"' . $m['title'] . '" ' . ($following && count($ids) > 1 ? '(' . fmt_date($m['meeting_date'], false) . ' and ' . (count($ids) - 1) . ' later) was cancelled' : '(' . fmt_date($m['meeting_date'], false) . ') was cancelled'), 'meetings/' . $id);
        }
        foreach ($ids as $cid) Audit::log('meeting.cancelled', 'meeting', $cid);
        flash('success', ucfirst($what === $m['title'] ? 'Meeting' : $what) . ' cancelled and invitees notified.' . $calendarNote);
        redirect('meetings/' . $id);
    }

    public function resend(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m)) abort(403);
        if (!$m['sent_email'] && !$m['sent_whatsapp']) { flash('info', 'This meeting uses personal invites only. Print the letters or share the invite from this page.'); redirect('meetings/' . $id); }
        foreach (Meetings::sendInvites($m, false) as $w) flash('error', $w);
        flash('success', 'Invite sent again to all invitees.');
        redirect('meetings/' . $id);
    }

    /** Invitees save what was discussed; the host and admins also set attendance. */
    public function saveMinutes(int $id): void
    {
        $m = Access::meeting($id);
        $canManage = Access::canManageMeeting($m);
        $isInvitee = (bool)DB::value('SELECT 1 FROM meeting_invitees WHERE meeting_id = ? AND user_id = ?', [$id, uid()]);
        if (!$canManage && !$isInvitee) abort(403);
        if ($m['status'] !== 'completed') { flash('error', 'Minutes open once the meeting is marked as over.'); redirect('meetings/' . $id); }
        self::requireOpenMinutes($m);
        self::saveDiscussion($id, (array)($_POST['discussion'] ?? []), (array)($_POST['decision'] ?? []));
        if ($canManage && isset($_POST['attendance_submitted'])) {
            $present = array_map('intval', (array)($_POST['attended'] ?? []));
            foreach (Meetings::invitees($id) as $i) DB::run('UPDATE meeting_invitees SET attended = ? WHERE meeting_id = ? AND user_id = ?', [in_array((int)$i['id'], $present, true) ? 1 : 0, $id, $i['id']]);
        }
        DB::update('meetings', ['minutes_locked_at' => now_utc(), 'updated_at' => now_utc()], ['id' => $id]); // saving locks the minutes
        Audit::log('meeting.minutes_saved', 'meeting', $id);
        $blank = (int)DB::value("SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = ? AND " . Meetings::ON_AGENDA . " AND (ai.discussion IS NULL OR ai.discussion = '')", [$id]);
        flash('success', 'Minutes saved and locked.' . ($blank ? ' ' . plural($blank, 'point') . ' still ' . ($blank === 1 ? 'has' : 'have') . ' no minutes.' : ($canManage && !$m['mom_sent_at'] ? ' Every point has minutes. You can send the MoM now.' : '')));
        redirect('meetings/' . $id);
    }

    /** Saved minutes are locked. Only the host (the meeting initiator) can unlock them to make changes. */
    public function unlockMinutes(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::isHost($m)) abort(403, 'Only the host (' . $m['organizer_name'] . ') can unlock the minutes.');
        if ($m['status'] !== 'completed' || empty($m['minutes_locked_at'])) redirect('meetings/' . $id);
        DB::update('meetings', ['minutes_locked_at' => null, 'updated_at' => now_utc()], ['id' => $id]);
        Audit::log('meeting.minutes_unlocked', 'meeting', $id);
        flash('success', 'Minutes unlocked. Save them again when you are done.');
        redirect('meetings/' . $id);
    }

    private static function requireOpenMinutes(array $m): void
    {
        if (empty($m['minutes_locked_at'])) return;
        flash('error', 'The minutes are saved and locked. ' . (Access::isHost($m) ? 'Click Edit minutes to change them.' : 'Only the host (' . $m['organizer_name'] . ') can unlock them.'));
        redirect('meetings/' . $m['id']);
    }

    /** A point that came up during the meeting, added to the minutes afterwards. */
    public function addPoint(int $id): void
    {
        $m = Access::meeting($id);
        $isInvitee = (bool)DB::value('SELECT 1 FROM meeting_invitees WHERE meeting_id = ? AND user_id = ?', [$id, uid()]);
        if (!Access::canManageMeeting($m) && !$isInvitee) abort(403);
        if ($m['status'] !== 'completed') { flash('error', 'Before the meeting, ask the host to add a point instead.'); redirect('meetings/' . $id); }
        $title = trim((string)input('title'));
        if ($title === '') redirect('meetings/' . $id);
        self::requireOpenMinutes($m);
        self::saveDiscussion($id, (array)($_POST['discussion'] ?? []), (array)($_POST['decision'] ?? []));
        $pos = (int)DB::value('SELECT COALESCE(MAX(position), 0) + 1 FROM agenda_items WHERE meeting_id = ?', [$id]);
        $pid = DB::insert('agenda_items', ['meeting_id' => $id, 'position' => $pos, 'title' => mb_substr($title, 0, 250), 'subpoints' => '[]', 'added_in_meeting' => 1, 'updated_by' => uid(), 'updated_at' => now_utc()]);
        flash('success', 'Point added to the minutes.');
        redirect('meetings/' . $id . '#point-' . $pid);
    }

    /** An invitee asks the host to add a discussion point. It waits under "By Admin’s permission" until the host decides. */
    public function requestPoint(int $id): void
    {
        $m = Access::meeting($id);
        $isInvitee = (bool)DB::value('SELECT 1 FROM meeting_invitees WHERE meeting_id = ? AND user_id = ?', [$id, uid()]);
        if (!$isInvitee || Access::isHost($m)) abort(403, 'Only invitees can ask the host to add a point. As the host, add points to the agenda directly.');
        if ($m['status'] !== 'scheduled') { flash('error', 'This meeting is ' . ($m['status'] === 'completed' ? 'over. Add the point to the minutes instead.' : 'cancelled.')); redirect('meetings/' . $id); }
        $title = mb_substr(trim((string)input('title')), 0, 250);
        $details = mb_substr(trim((string)input('details', '')), 0, 4000);
        $mins = max(0, min(120, (int)input('mins', 0)));
        if (mb_strlen($title) < 3) { flash('error', 'Describe the point you’d like to discuss.'); redirect('meetings/' . $id . '#ask'); }
        $pending = (int)DB::value("SELECT COUNT(*) FROM agenda_items WHERE meeting_id = ? AND requested_by = ? AND request_status = 'pending'", [$id, uid()]);
        if ($pending >= 5) { flash('error', 'You already have 5 requests waiting. Wait for the host to review them.'); redirect('meetings/' . $id . '#ask'); }
        $pos = (int)DB::value('SELECT COALESCE(MAX(position), 0) + 1 FROM agenda_items WHERE meeting_id = ?', [$id]);
        $itemId = DB::insert('agenda_items', [
            'meeting_id' => $id, 'position' => $pos, 'title' => $title, 'details' => $details !== '' ? $details : null, 'presenter_id' => uid(),
            'minutes_allotted' => $mins, 'subpoints' => '[]', 'request_status' => 'pending', 'requested_by' => uid(), 'updated_by' => uid(), 'updated_at' => now_utc(),
        ]);
        Notifier::inApp((int)$m['organizer_id'], user()['name'] . ' asked to add "' . $title . '" to "' . $m['title'] . '"', 'meetings/' . $id . '#permission');
        $host = Access::member((int)$m['organizer_id']);
        if ($host && $host['status'] === 'active' && (int)$host['id'] !== uid()) {
            $html = Notifier::layout('A point to add to ' . $m['title'], '<p>' . e(user()['name']) . ' would like to discuss:</p><p style="font-size:16px"><b>' . e($title) . '</b></p>' . ($details !== '' ? '<p style="white-space:pre-wrap;color:#4A5663">' . e($details) . '</p>' : '') . '<p>Approve it to add it to the agenda, or decline it with a message.</p>', 'Review request', url('meetings/' . $id . '#permission'), user()['org_name']);
            Notifier::email(org_id(), $host['email'], $host['name'], 'Point request: ' . $title, $html);
        }
        Audit::log('meeting.point_requested', 'agenda_item', $itemId);
        flash('success', 'Sent to ' . $m['organizer_name'] . '. The point is added to the agenda once they approve it.');
        redirect('meetings/' . $id . '#ask');
    }

    /** The host (or an admin) approves or declines a requested point, with an optional message. */
    public function reviewRequest(int $id, int $item): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m)) abort(403, 'Only the host or an admin can review point requests.');
        self::requireOpenMinutes($m);
        $row = DB::one("SELECT * FROM agenda_items WHERE id = ? AND meeting_id = ? AND request_status IS NOT NULL", [$item, $id]);
        if (!$row) abort(404);
        $decision = input('decision') === 'approve' ? 'approved' : (input('decision') === 'decline' ? 'declined' : null);
        if (!$decision) redirect('meetings/' . $id . '#permission');
        $note = mb_substr(trim((string)input('note', '')), 0, 500);
        if ($decision === 'declined' && $note === '') { flash('error', 'Add a short message so ' . self::firstName((int)$row['requested_by']) . ' knows why.'); redirect('meetings/' . $id . '#request-' . $item); }
        $data = ['request_status' => $decision, 'review_note' => $note !== '' ? $note : null, 'reviewed_by' => uid(), 'reviewed_at' => now_utc(), 'updated_at' => now_utc()];
        if ($decision === 'approved' && $row['request_status'] !== 'approved') {
            // Goes to the end of the agenda
            $data['position'] = (int)DB::value('SELECT COALESCE(MAX(position), 0) + 1 FROM agenda_items ai WHERE ai.meeting_id = ? AND ' . Meetings::ON_AGENDA, [$id]);
        }
        DB::update('agenda_items', $data, ['id' => $item]);
        if ($row['requested_by'] && (int)$row['requested_by'] !== uid()) {
            $msg = user()['name'] . ($decision === 'approved' ? ' added your point "' . $row['title'] . '" to the agenda' : ' declined your point "' . $row['title'] . '"') . ($note !== '' ? ': ' . $note : '');
            Notifier::inApp((int)$row['requested_by'], $msg, 'meetings/' . $id . '#ask');
        }
        Audit::log('meeting.point_' . $decision, 'agenda_item', $item);
        flash('success', $decision === 'approved' ? 'Added to the agenda.' : 'Request declined. ' . self::firstName((int)$row['requested_by']) . ' has been told.');
        redirect('meetings/' . $id . '#permission');
    }

    public function addAction(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::isHost($m)) abort(403, 'Only the host (' . $m['organizer_name'] . ') can assign action items.');
        $pointId = (int)input('agenda_item_id');
        if (!DB::value('SELECT 1 FROM agenda_items ai WHERE ai.id = ? AND ai.meeting_id = ? AND ' . Meetings::ON_AGENDA, [$pointId, $id])) abort(404);
        // Keep any minutes typed on the page before the action was added
        if (empty($m['minutes_locked_at'])) self::saveDiscussion($id, (array)($_POST['discussion'] ?? []), (array)($_POST['decision'] ?? []));
        $owner = (int)input('owner_id');
        $inv = array_map('intval', array_column(array_filter(Access::members(), fn($u) => $u['status'] !== 'disabled'), 'id'));
        $title = trim((string)input('title'));
        $deadline = (string)input('deadline');
        if ($title === '' || !in_array($owner, $inv, true) || !valid_date($deadline)) { flash('error', 'Add a description, a responsible person from your team and a deadline.'); redirect('meetings/' . $id . '#point-' . $pointId); }
        $priority = in_array(input('priority'), ['high', 'medium', 'low'], true) ? input('priority') : 'medium';
        $ownerRow = Access::member($owner);
        Actions::create(['meeting_id' => $id, 'agenda_item_id' => $pointId, 'project_id' => $m['project_id'] ?: null, 'title' => $title, 'priority' => $priority,
            'owner_id' => $owner, 'owner_name' => $ownerRow['name'], 'deadline' => $deadline, 'quiet' => true]);
        flash('success', 'Action item added.');
        redirect('meetings/' . $id . '#point-' . $pointId);
    }

    /** Attendance: the host marks, approves or declines; an invitee can ask the host to confirm they attended. */
    public function attendance(int $id): void
    {
        $m = Access::meeting($id);
        $canManage = Access::canManageMeeting($m);
        $action = (string)input('action');
        $target = (int)input('user_id');
        if (!empty($m['minutes_locked_at'])) { flash('error', 'The minutes are locked, so attendance is fixed.'); redirect('meetings/' . $id . '#attendance'); }
        $row = DB::one('SELECT 1 AS x FROM meeting_invitees WHERE meeting_id = ? AND user_id = ?', [$id, $target]);
        if (!$row) { flash('error', 'That person is not invited to this meeting.'); redirect('meetings/' . $id . '#attendance'); }

        if ($canManage && in_array($action, ['mark', 'unmark', 'approve', 'decline'], true)) {
            if ($action === 'mark' || $action === 'approve') {
                DB::run('UPDATE meeting_invitees SET attended = 1, attend_req = NULL WHERE meeting_id = ? AND user_id = ?', [$id, $target]);
            } elseif ($action === 'unmark') {
                DB::run('UPDATE meeting_invitees SET attended = 0, attend_req = NULL WHERE meeting_id = ? AND user_id = ?', [$id, $target]);
            } else {
                DB::run("UPDATE meeting_invitees SET attended = 0, attend_req = 'declined' WHERE meeting_id = ? AND user_id = ?", [$id, $target]);
                if ($target !== uid()) Notifier::inApp($target, 'Your attendance for "' . $m['title'] . '" was not approved.', 'meetings/' . $id);
            }
            Audit::log('meeting.attendance_' . $action, 'meeting', $id);
        } elseif ($action === 'request' && $target === uid() && (int)$m['organizer_id'] !== uid()) {
            DB::run("UPDATE meeting_invitees SET attend_req = 'pending' WHERE meeting_id = ? AND user_id = ? AND attended = 0", [$id, $target]);
            Notifier::inApp((int)$m['organizer_id'], user()['name'] . ' says they attended "' . $m['title'] . '". Please confirm.', 'meetings/' . $id);
        } else {
            abort(403);
        }
        redirect('meetings/' . $id . '#attendance');
    }

    public function rsvp(int $id): void
    {
        $m = Access::meeting($id);
        $r = in_array(input('rsvp'), ['yes', 'no', 'maybe'], true) ? input('rsvp') : 'pending';
        DB::run('UPDATE meeting_invitees SET rsvp = ? WHERE meeting_id = ? AND user_id = ?', [$r, $id, uid()]);
        if ((int)$m['organizer_id'] !== uid() && in_array($r, ['yes', 'no'], true)) {
            Notifier::inApp((int)$m['organizer_id'], user()['name'] . ($r === 'yes' ? ' is coming to "' : ' can’t make it to "') . $m['title'] . '"', 'meetings/' . $id);
        }
        flash('success', 'Response saved.');
        redirect('meetings/' . $id);
    }

    public function mom(int $id): void
    {
        $m = Access::meeting($id);
        Meetings::markMinutesSeen($m);
        echo self::momView('meetings/mom', $m, input('lang'), ['title' => 'Minutes: ' . $m['title']]);
    }

    public function sendMom(int $id): void
    {
        $m = Access::meeting($id);
        if (!Access::canManageMeeting($m)) abort(403);
        if ($m['status'] !== 'completed') { flash('error', 'Mark the meeting as over and record the minutes first.'); redirect('meetings/' . $id); }
        $lang = Translator::configured() ? Translator::valid(input('lang')) : null;
        $compose = function (callable $t) use ($m, $id): array {
            $d = self::momData($m, $t);
            return [
                'body' => View::partial('meetings/_mom_email', $d),
                'title' => $t('Minutes of meeting') . ': ' . $t($m['title']),
                'open' => $t('Open the minutes'),
                'wa' => $t('The minutes of this meeting are ready') . ': ' . $t($m['title']),
                'invitees' => $d['invitees'],
            ];
        };
        try {
            $mail = $lang ? Translator::render($lang, $compose) : $compose(fn($s) => (string)$s);
        } catch (\Throwable $e) {
            flash('error', 'Couldn’t translate the minutes, so nothing was sent. ' . $e->getMessage());
            redirect('meetings/' . $id . '/mom');
        }
        $count = 0;
        foreach ($mail['invitees'] as $i) {
            Notifier::email(org_id(), $i['email'], $i['name'], 'Minutes: ' . $m['title'] . ' (' . fmt_date($m['meeting_date'], false) . ')', Notifier::layout($mail['title'], $mail['body'], $lang ? $mail['open'] : 'Open in ' . config('app.name'), url('meetings/' . $id . '/mom', ['lang' => $lang]), user()['org_name']));
            if ($m['sent_whatsapp'] && $i['phone']) Notifier::whatsapp(org_id(), (string)$i['phone'], $i['name'], $lang ? $mail['wa'] . ': ' . url('meetings/' . $id . '/mom', ['lang' => $lang]) : "Minutes of \"{$m['title']}\" are ready: " . url('meetings/' . $id . '/mom'));
            if ((int)$i['id'] !== uid()) Notifier::inApp((int)$i['id'], 'Minutes of "' . $m['title'] . '" are ready', 'meetings/' . $id . '/mom');
            $count++;
        }
        DB::update('meetings', ['mom_sent_at' => now_utc()], ['id' => $id]);
        Audit::log('meeting.mom_sent', 'meeting', $id);
        flash('success', 'Minutes sent to ' . plural($count, 'person', 'people') . ($lang ? ' in ' . Translator::LANGS[$lang] : '') . '.');
        redirect('meetings/' . $id . '/mom');
    }

    // ---------------------------------------------------------------- printing (host only)

    public function printInvite(int $id): void
    {
        $m = self::hostMeeting($id);
        echo view('meetings/print-invite', ['m' => $m, 'agenda' => Meetings::agenda($id), 'invitees' => Meetings::invitees($id), 'project' => Meetings::project($m), 'title' => 'Invitation: ' . $m['title']], 'layouts/print');
    }

    public function printPersonal(int $id): void
    {
        $m = self::hostMeeting($id);
        echo view('meetings/print-personal', ['m' => $m, 'agenda' => Meetings::agenda($id), 'invitees' => Meetings::invitees($id), 'project' => Meetings::project($m), 'title' => 'Personal invitations: ' . $m['title']], 'layouts/print');
    }

    public function printAttendance(int $id): void
    {
        $m = self::hostMeeting($id);
        echo view('meetings/print-attendance', ['m' => $m, 'invitees' => Meetings::invitees($id), 'project' => Meetings::project($m), 'title' => 'Attendance register: ' . $m['title']], 'layouts/print');
    }

    public function printActions(int $id): void
    {
        $m = self::hostMeeting($id);
        $d = self::momData($m);
        echo view('meetings/print-actions', $d + ['title' => 'Action items: ' . $m['title']], 'layouts/print');
    }

    public function printMom(int $id): void
    {
        $m = self::hostMeeting($id);
        echo self::momView('meetings/_mom_sheet', $m, input('lang'), ['title' => 'Minutes: ' . $m['title'], 'print' => true], 'layouts/print');
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

    // ---------------------------------------------------------------- helpers

    /** Printing is for the host only (the organiser), not other admins. */
    private static function hostMeeting(int $id): array
    {
        $m = Access::meeting($id);
        if (!Access::isHost($m)) abort(403, 'Only the host (' . $m['organizer_name'] . ') can print this meeting’s documents.');
        return $m;
    }

    private static function saveDiscussion(int $meetingId, array $discussion, array $decision = []): void
    {
        foreach ($discussion as $pointId => $text) {
            $sql = 'UPDATE agenda_items ai SET ai.discussion = ?, ai.updated_by = ?, ai.updated_at = UTC_TIMESTAMP()';
            $params = [mb_substr(trim((string)$text), 0, 20000), uid()];
            if (array_key_exists($pointId, $decision) && !is_array($decision[$pointId])) { // the decision is optional; a blank box clears it
                $d = mb_substr(trim((string)$decision[$pointId]), 0, 5000);
                $sql .= ', ai.decision = ?';
                $params[] = $d === '' ? null : $d;
            }
            DB::run($sql . ' WHERE ai.id = ? AND ai.meeting_id = ? AND ' . Meetings::ON_AGENDA, array_merge($params, [(int)$pointId, $meetingId]));
        }
    }

    private static function firstName(int $userId): string
    {
        return explode(' ', (string)(DB::value('SELECT name FROM users WHERE id = ?', [$userId]) ?? 'them'))[0];
    }

    /** Draws a minutes view, translated when a language is chosen. Falls back to English with a note if translation isn't set up or fails. */
    private static function momView(string $template, array $m, mixed $lang, array $extra = [], ?string $layout = 'layouts/app'): string
    {
        $lang = Translator::valid($lang);
        $extra += ['lang' => $lang, 'translateReady' => Translator::configured(), 'translateError' => null];
        if (!$lang || !$extra['translateReady']) return View::render($template, self::momData($m) + $extra, $layout);
        try {
            return (string)Translator::render($lang, fn(callable $t) => View::render($template, self::momData($m, $t) + $extra, $layout));
        } catch (\Throwable $e) {
            return View::render($template, self::momData($m) + ['translateError' => $e->getMessage()] + $extra, $layout);
        }
    }

    private static function momData(array $m, ?callable $t = null): array
    {
        $id = (int)$m['id'];
        $agenda = Meetings::agenda($id);
        $invitees = Meetings::invitees($id);
        $actions = DB::all('SELECT a.*, u.name AS owner_name FROM actions a JOIN users u ON u.id = a.owner_id WHERE a.meeting_id = ? ORDER BY a.agenda_item_id, a.id', [$id]);
        $ordered = [];
        foreach ($agenda as $p) foreach ($actions as $a) if ((int)$a['agenda_item_id'] === (int)$p['id']) $ordered[] = $a;
        $signature = (string)DB::value('SELECT signature FROM user_settings WHERE user_id = ?', [$m['organizer_id']]);
        $organizerTitle = (string)DB::value('SELECT title FROM users WHERE id = ?', [$m['organizer_id']]);
        $orgName = (string)DB::value('SELECT name FROM organizations WHERE id = ?', [$m['org_id']]);
        $declined = array_values(array_filter(Meetings::requests($id), fn($r) => $r['request_status'] === 'declined'));
        $carried = Meetings::carriedForward($m);
        $canManage = Access::canManageMeeting($m);
        return ['carried' => $carried, 'seen' => $canManage && !empty($m['minutes_locked_at']) ? Meetings::seenSummary($m) : null,
            'waText' => Meetings::minutesText($m, $agenda, $ordered, $invitees, $carried),
            't' => $t ?? fn($s) => (string)$s, 'm' => $m, 'agenda' => $agenda, 'declined' => $declined, 'invitees' => $invitees, 'actions' => $ordered, 'canManage' => Access::canManageMeeting($m), 'isHost' => Access::isHost($m),
            'signature' => $signature, 'organizerTitle' => $organizerTitle, 'orgName' => $orgName, 'project' => Meetings::project($m)];
    }
}
