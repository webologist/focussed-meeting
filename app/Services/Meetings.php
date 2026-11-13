<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class Meetings
{
    /**
     * SQL condition (alias ai) for points that are on the agenda: regular points, and points an attendee
     * asked to add once the host approved them. Pending and declined requests are not on the agenda.
     */
    public const ON_AGENDA = "(ai.request_status IS NULL OR ai.request_status = 'approved')";

    /** How a meeting can repeat (key => label shown in the planner). */
    public const REPEATS = [
        'daily'    => 'Every day',
        'weekdays' => 'Every weekday (Mon–Fri)',
        'weekly'   => 'Every week',
        'biweekly' => 'Every 2 weeks',
        'monthly'  => 'Every month (same date)',
    ];
    /** A series is created in one go, so keep it to a sensible size. */
    public const MAX_OCCURRENCES = 60;

    /** "every week" – used in sentences like "Repeats every week until 31 Dec 2026". */
    public static function repeatPhrase(string $rule): string
    {
        return ['daily' => 'every day', 'weekdays' => 'every weekday (Mon–Fri)', 'weekly' => 'every week', 'biweekly' => 'every 2 weeks', 'monthly' => 'every month'][$rule] ?? $rule;
    }

    /** Short label for list pills: "Weekly". */
    public static function repeatShort(string $rule): string
    {
        return ['daily' => 'Daily', 'weekdays' => 'Weekdays', 'weekly' => 'Weekly', 'biweekly' => 'Every 2 weeks', 'monthly' => 'Monthly'][$rule] ?? 'Repeats';
    }

    /** Why this start date can't use the repeat rule (or null when it is fine). */
    public static function repeatProblem(string $rule, string $startDate): ?string
    {
        if (!isset(self::REPEATS[$rule])) return 'Pick how the meeting should repeat.';
        $d = new \DateTimeImmutable($startDate);
        if ($rule === 'weekdays' && (int)$d->format('N') > 5) return 'A Monday–Friday repeat has to start on a weekday. Pick a weekday as the first date.';
        if ($rule === 'monthly' && (int)$d->format('j') > 28) return 'A monthly repeat needs a first date between the 1st and the 28th, so it exists in every month.';
        return null;
    }

    /**
     * Every date of a series from $startDate to $until (inclusive), starting with $startDate itself.
     * Stops one past MAX_OCCURRENCES so the caller can tell the series is too long.
     * @return string[] Y-m-d dates
     */
    public static function occurrences(string $startDate, string $rule, string $until): array
    {
        if (self::repeatProblem($rule, $startDate) !== null) return [];
        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($until);
        $dates = [];
        $d = $start;
        for ($i = 0; $d <= $end && count($dates) <= self::MAX_OCCURRENCES; $i++) {
            $dates[] = $d->format('Y-m-d');
            switch ($rule) {
                case 'daily':    $d = $d->modify('+1 day'); break;
                case 'weekdays': $d = $d->modify('+1 day'); while ((int)$d->format('N') > 5) $d = $d->modify('+1 day'); break;
                case 'weekly':   $d = $d->modify('+7 days'); break;
                case 'biweekly': $d = $d->modify('+14 days'); break;
                case 'monthly':  $ym = $start->modify('first day of this month')->modify('+' . ($i + 1) . ' months'); $d = $ym->setDate((int)$ym->format('Y'), (int)$ym->format('n'), (int)$start->format('j')); break;
            }
        }
        return $dates;
    }

    /** iCalendar RRULE value (Google Calendar and .ics files). $count includes the first meeting. */
    public static function rrule(string $rule, int $count): string
    {
        return match ($rule) {
            'daily'    => "FREQ=DAILY;COUNT=$count",
            'weekdays' => "FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR;COUNT=$count",
            'weekly'   => "FREQ=WEEKLY;COUNT=$count",
            'biweekly' => "FREQ=WEEKLY;INTERVAL=2;COUNT=$count",
            'monthly'  => "FREQ=MONTHLY;COUNT=$count",
            default    => '',
        };
    }

    /** Microsoft Graph "recurrence" object for the same rule. */
    public static function graphRecurrence(string $rule, int $count, string $startDate): array
    {
        $dow = strtolower((new \DateTimeImmutable($startDate))->format('l'));
        $pattern = match ($rule) {
            'daily'    => ['type' => 'daily', 'interval' => 1],
            'weekdays' => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'firstDayOfWeek' => 'monday'],
            'weekly'   => ['type' => 'weekly', 'interval' => 1, 'daysOfWeek' => [$dow], 'firstDayOfWeek' => 'monday'],
            'biweekly' => ['type' => 'weekly', 'interval' => 2, 'daysOfWeek' => [$dow], 'firstDayOfWeek' => 'monday'],
            default    => ['type' => 'absoluteMonthly', 'interval' => 1, 'dayOfMonth' => (int)(new \DateTimeImmutable($startDate))->format('j')],
        };
        return ['pattern' => $pattern, 'range' => ['type' => 'numbered', 'startDate' => $startDate, 'numberOfOccurrences' => $count]];
    }

    /** The series a meeting belongs to: how it repeats, how many meetings, which one this is. Null for a one-off meeting. */
    public static function series(array $m): ?array
    {
        if (empty($m['series_id'])) return null;
        $rows = DB::all('SELECT id, meeting_date, start_time, status FROM meetings WHERE series_id = ? AND org_id = ? ORDER BY meeting_date, start_time, id', [$m['series_id'], $m['org_id']]);
        if (!$rows) return null;
        $pos = 1;
        foreach ($rows as $i => $r) if ((int)$r['id'] === (int)$m['id']) $pos = $i + 1;
        $rule = (string)($m['recurrence'] ?? '');
        $last = end($rows)['meeting_date'];
        return [
            'rule' => $rule, 'rows' => $rows, 'count' => count($rows), 'position' => $pos, 'first_date' => $rows[0]['meeting_date'], 'last_date' => $last,
            'sentence' => 'Repeats ' . self::repeatPhrase($rule) . ' until ' . fmt_date($last) . ' (' . plural(count($rows), 'meeting') . ')',
        ];
    }

    /** Agenda points (including approved requests), in order, with presenter name and decoded sub-points. */
    public static function agenda(int $meetingId): array
    {
        $rows = DB::all('SELECT ai.*, u.name AS presenter_name, rq.name AS requester_name
                           FROM agenda_items ai LEFT JOIN users u ON u.id = ai.presenter_id LEFT JOIN users rq ON rq.id = ai.requested_by
                          WHERE ai.meeting_id = ? AND ' . self::ON_AGENDA . ' ORDER BY ai.position, ai.id', [$meetingId]);
        foreach ($rows as &$r) $r['subpoints'] = json_decode((string)$r['subpoints'], true) ?: [];
        return $rows;
    }

    /** Discussion points attendees asked to add (pending, approved and declined), newest first within each status. */
    public static function requests(int $meetingId): array
    {
        $rows = DB::all("SELECT ai.*, rq.name AS requester_name, rv.name AS reviewer_name
                           FROM agenda_items ai LEFT JOIN users rq ON rq.id = ai.requested_by LEFT JOIN users rv ON rv.id = ai.reviewed_by
                          WHERE ai.meeting_id = ? AND ai.request_status IS NOT NULL
                          ORDER BY FIELD(ai.request_status, 'pending', 'approved', 'declined'), ai.id DESC", [$meetingId]);
        foreach ($rows as &$r) $r['subpoints'] = json_decode((string)$r['subpoints'], true) ?: [];
        return $rows;
    }

    public static function invitees(int $meetingId): array
    {
        return DB::all('SELECT u.id, u.name, u.email, u.phone, u.title, u.status, i.attended, i.rsvp FROM meeting_invitees i JOIN users u ON u.id = i.user_id WHERE i.meeting_id = ? ORDER BY u.name', [$meetingId]);
    }

    public static function project(array $m): ?array
    {
        return empty($m['project_id']) ? null : DB::one('SELECT * FROM projects WHERE id = ? AND org_id = ?', [$m['project_id'], $m['org_id']]);
    }

    public static function status(array $m, ?int $blankPoints = null): array
    {
        if ($m['status'] === 'cancelled') return ['Cancelled', 's-neutral'];
        if ($m['status'] === 'scheduled') return $m['meeting_date'] < today() ? ['Meeting over? Add minutes', 's-pending'] : ['Scheduled', 's-sched'];
        $blank = $blankPoints ?? (int)DB::value("SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = ? AND " . self::ON_AGENDA . " AND (ai.discussion IS NULL OR ai.discussion = '')", [$m['id']]);
        if ($blank) return ['Minutes incomplete', 's-pending'];
        return $m['mom_sent_at'] ? ['MoM sent', 's-done'] : ['Minutes recorded', 's-done'];
    }

    public static function where(array $m): string
    {
        return $m['platform'] === 'inperson' ? ($m['location'] ?: 'Location to be confirmed') : ($m['join_url'] ?: platform_label($m['platform']) . ' link to follow');
    }

    public static function agendaHtml(array $agenda): string
    {
        $h = '<ol style="padding-left:20px;margin:6px 0">';
        foreach ($agenda as $a) {
            $meta = array_filter([$a['presenter_name'] ?? null, $a['minutes_allotted'] ? $a['minutes_allotted'] . ' min' : null]);
            $h .= '<li style="margin-bottom:8px"><b>' . e($a['title']) . '</b>' . ($meta ? ' <span style="color:#7B8794;font-size:13px">· ' . e(implode(' · ', $meta)) . '</span>' : '');
            if (!empty($a['details'])) $h .= '<div style="color:#4A5663;font-size:14px;white-space:pre-wrap">' . e($a['details']) . '</div>';
            if (!empty($a['subpoints'])) { $h .= '<ul style="color:#4A5663;font-size:14px;margin:4px 0;padding-left:18px">'; foreach ($a['subpoints'] as $s) $h .= '<li>' . e($s) . '</li>'; $h .= '</ul>'; }
            $h .= '</li>';
        }
        return $h . '</ol>';
    }

    public static function agendaText(array $agenda): string
    {
        $lines = [];
        foreach ($agenda as $i => $a) {
            $lines[] = ($i + 1) . '. ' . $a['title'] . ($a['minutes_allotted'] ? ' (' . $a['minutes_allotted'] . 'm)' : '');
            foreach ($a['subpoints'] as $s) $lines[] = '   • ' . $s;
        }
        return implode("\n", $lines);
    }

    /** Plain-text invitation the host can share personally (copy, WhatsApp, own mail app). */
    public static function inviteText(array $m, array $agenda, string $organizerName, ?string $toName = null): string
    {
        $when = gmdate('l', strtotime($m['meeting_date'] . ' 00:00:00 UTC')) . ', ' . fmt_date($m['meeting_date']) . ', ' . fmt_range($m) . ' (' . (int)$m['duration_min'] . ' min, ' . $m['timezone'] . ')';
        $series = self::series($m);
        if ($series) $when .= "\n" . $series['sentence'];
        $note = trim((string)($m['personal_note'] ?? ''));
        return ($toName ? 'Hi ' . explode(' ', trim($toName))[0] . ",\n\n" : "Hi,\n\n")
            . 'You’re invited to "' . $m['title'] . "\".\n\n"
            . ($note !== '' ? $note . "\n\n" : '')
            . "When: $when\nWhere: " . self::where($m) . "\nHost: $organizerName\n\n"
            . "Agenda:\n" . self::agendaText($agenda) . "\n\n"
            . 'Meeting page (agenda, minutes and action items): ' . url('meetings/' . $m['id']) . "\n\n"
            . "Thanks,\n" . $organizerName;
    }

    /**
     * Create the calendar event (Meet/Teams) if needed and send the agenda to every invitee
     * by the channels chosen for the meeting. Returns a list of warnings to show the host.
     */
    public static function sendInvites(array $m, bool $createEvent = true): array
    {
        $warnings = [];
        // A recurring series gets ONE invitation (the first meeting, with the repeat rule). Never one per date.
        if (!empty($m['series_id']) && (int)$m['series_id'] !== (int)$m['id']) $m = DB::one('SELECT * FROM meetings WHERE id = ?', [$m['series_id']]) ?: $m;
        $series = self::series($m);
        if ($series && $series['count'] > 1) {
            $m['_rrule'] = self::rrule((string)$m['recurrence'], $series['count']);
            $m['_graph_recurrence'] = self::graphRecurrence((string)$m['recurrence'], $series['count'], $m['meeting_date']);
        } else {
            $series = null;
        }
        $orgId = (int)$m['org_id'];
        $agenda = self::agenda((int)$m['id']);
        $invitees = self::invitees((int)$m['id']);
        $organizer = DB::one('SELECT id, name, email FROM users WHERE id = ?', [$m['organizer_id']]);
        $orgName = (string)DB::value('SELECT name FROM organizations WHERE id = ?', [$orgId]);
        $project = self::project($m);
        $note = trim((string)($m['personal_note'] ?? ''));
        $automatic = !empty($m['sent_email']) || !empty($m['sent_whatsapp']);
        $calendarSent = false;

        if ($createEvent && $m['platform'] !== 'inperson' && !$m['external_event_id']) {
            $others = array_values(array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
            // Personal invite only: create the event for the join link, without the calendar emailing invites itself
            if (!$automatic) $others = [];
            $desc = ($note !== '' ? $note . "\n\n" : '') . self::agendaText($agenda) . "\n\nMinutes and action items: " . url('meetings/' . $m['id']);
            try {
                if ($m['platform'] === 'meet') {
                    $ev = GoogleCalendar::createEvent($orgId, $m, array_column($others, 'email'), $desc);
                } else {
                    $ev = MicrosoftGraph::createEvent($orgId, $m, $others, ($note !== '' ? '<p style="white-space:pre-wrap">' . e($note) . '</p>' : '') . self::agendaHtml($agenda) . '<p><a href="' . e(url('meetings/' . $m['id'])) . '">Minutes and action items</a></p>');
                }
                DB::update('meetings', ['join_url' => $ev['join_url'], 'external_event_id' => $ev['id'], 'updated_at' => now_utc()], ['id' => $m['id']]);
                $m['join_url'] = $ev['join_url'];
                $m['external_event_id'] = $ev['id'];
                $calendarSent = $automatic;
            } catch (\Throwable $e) {
                $warnings[] = 'The ' . platform_label($m['platform']) . ' link could not be created: ' . $e->getMessage() . ($automatic ? ' Invites were sent with a calendar file instead.' : '');
            }
        } elseif ($m['external_event_id']) {
            $calendarSent = $automatic;
        }

        $when = fmt_date($m['meeting_date']) . ', ' . fmt_range($m) . ' (' . $m['duration_min'] . ' min, ' . $m['timezone'] . ')';
        $repeats = $series ? $series['sentence'] : '';
        $where = self::where($m);
        $ics = Ics::invite($m, $organizer, array_map(fn($i) => ['name' => $i['name'], 'email' => $i['email']], $invitees), self::agendaText($agenda) . "\n\n" . url('meetings/' . $m['id']));
        $failed = 0;
        foreach ($invitees as $i) {
            if ((int)$i['id'] === (int)$m['organizer_id']) continue;
            if ($m['sent_email']) {
                $body = '<p>Hi ' . e(explode(' ', $i['name'])[0]) . ', you’re invited to <b>' . e($m['title']) . '</b>.</p>'
                    . ($note !== '' ? '<p style="border-left:3px solid #1F6F78;padding:2px 0 2px 12px;white-space:pre-wrap">' . e($note) . '</p>' : '')
                    . '<p><span style="color:#7B8794">When</span> ' . e($when) . ($repeats !== '' ? '<br><span style="color:#7B8794">Repeats</span> ' . e(mb_substr($repeats, 8)) : '') . '<br><span style="color:#7B8794">Where</span> ' . (preg_match('~^https?://~', $where) ? '<a href="' . e($where) . '">' . e($where) . '</a>' : e($where))
                    . '<br><span style="color:#7B8794">Host</span> ' . e($organizer['name']) . ($project ? '<br><span style="color:#7B8794">Project</span> ' . e($project['name']) : '') . '</p>'
                    . '<p style="margin-bottom:0"><b>Agenda</b></p>' . self::agendaHtml($agenda)
                    . ($i['status'] === 'invited' ? '<p style="color:#4A5663;font-size:14px">You’ll also get a separate email to set up your account, so you can add minutes after the meeting.</p>' : '')
                    . '<p style="color:#4A5663;font-size:14px">Want to discuss something else? Open the meeting to ask the host to add a point. After the meeting, add minutes and see your action items there.</p>';
                $html = Notifier::layout('Invitation: ' . $m['title'], $body, 'Open meeting', url('meetings/' . $m['id']), $orgName);
                $atts = $calendarSent ? [] : [['name' => 'invite.ics', 'mime' => 'text/calendar; method=REQUEST; charset=UTF-8', 'content' => $ics]];
                $oid = Notifier::email($orgId, $i['email'], $i['name'], 'Invitation: ' . $m['title'] . ' · ' . fmt_date($m['meeting_date'], false) . ', ' . fmt_time($m['start_time']), $html, $atts);
                if (DB::value('SELECT status FROM outbox WHERE id = ?', [$oid]) !== 'sent') $failed++;
            }
            if ($m['sent_whatsapp'] && $i['phone']) {
                Notifier::whatsapp($orgId, (string)$i['phone'], $i['name'], "*{$m['title']}*\n" . ($note !== '' ? "$note\n" : '') . "📅 $when" . ($repeats !== '' ? " · $repeats" : '') . "\n📍 $where\n\nAgenda:\n" . self::agendaText($agenda) . "\n\n" . url('meetings/' . $m['id']));
            }
            Notifier::inApp((int)$i['id'], $organizer['name'] . ' invited you to "' . $m['title'] . '" on ' . fmt_date($m['meeting_date'], false) . ($series ? ', repeating ' . self::repeatPhrase((string)$m['recurrence']) : ''), 'meetings/' . $m['id']);
        }
        if ($failed) $warnings[] = plural($failed, 'invite email') . ' couldn’t be sent yet and will be retried automatically. Check Integrations → Email.';
        DB::update('meetings', ['invite_sent_at' => now_utc()], ['id' => $m['id']]);
        return $warnings;
    }
}
