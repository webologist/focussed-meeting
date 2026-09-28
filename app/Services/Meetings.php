<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class Meetings
{
    public static function agenda(int $meetingId): array
    {
        $rows = DB::all('SELECT ai.*, u.name AS presenter_name FROM agenda_items ai LEFT JOIN users u ON u.id = ai.presenter_id WHERE ai.meeting_id = ? ORDER BY ai.position, ai.id', [$meetingId]);
        foreach ($rows as &$r) $r['subpoints'] = json_decode((string)$r['subpoints'], true) ?: [];
        return $rows;
    }

    public static function invitees(int $meetingId): array
    {
        return DB::all('SELECT u.id, u.name, u.email, u.phone, u.status, i.attended, i.rsvp FROM meeting_invitees i JOIN users u ON u.id = i.user_id WHERE i.meeting_id = ? ORDER BY u.name', [$meetingId]);
    }

    public static function status(array $m, ?int $blankPoints = null): array
    {
        if ($m['status'] === 'cancelled') return ['Cancelled', 's-neutral'];
        if ($m['status'] === 'scheduled') return $m['meeting_date'] < today() ? ['Meeting over? Add minutes', 's-pending'] : ['Scheduled', 's-sched'];
        $blank = $blankPoints ?? (int)DB::value("SELECT COUNT(*) FROM agenda_items WHERE meeting_id = ? AND (discussion IS NULL OR discussion = '')", [$m['id']]);
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

    /**
     * Create the calendar event (Meet/Teams) if needed and send the agenda to every invitee.
     * Returns a list of warnings to show the organiser.
     */
    public static function sendInvites(array $m, bool $createEvent = true): array
    {
        $warnings = [];
        $orgId = (int)$m['org_id'];
        $agenda = self::agenda((int)$m['id']);
        $invitees = self::invitees((int)$m['id']);
        $organizer = DB::one('SELECT id, name, email FROM users WHERE id = ?', [$m['organizer_id']]);
        $orgName = (string)DB::value('SELECT name FROM organizations WHERE id = ?', [$orgId]);
        $calendarSent = false;

        if ($createEvent && $m['platform'] !== 'inperson' && !$m['external_event_id']) {
            $others = array_values(array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
            try {
                if ($m['platform'] === 'meet') {
                    $ev = GoogleCalendar::createEvent($orgId, $m, array_column($others, 'email'), self::agendaText($agenda) . "\n\nMinutes and action items: " . url('meetings/' . $m['id']));
                } else {
                    $ev = MicrosoftGraph::createEvent($orgId, $m, $others, self::agendaHtml($agenda) . '<p><a href="' . e(url('meetings/' . $m['id'])) . '">Minutes and action items</a></p>');
                }
                DB::update('meetings', ['join_url' => $ev['join_url'], 'external_event_id' => $ev['id'], 'updated_at' => now_utc()], ['id' => $m['id']]);
                $m['join_url'] = $ev['join_url'];
                $m['external_event_id'] = $ev['id'];
                $calendarSent = true;
            } catch (\Throwable $e) {
                $warnings[] = 'The ' . platform_label($m['platform']) . ' link could not be created: ' . $e->getMessage() . ' Invites were sent with a calendar file instead.';
            }
        } elseif ($m['external_event_id']) {
            $calendarSent = true;
        }

        $when = fmt_date($m['meeting_date']) . ', ' . fmt_time($m['start_time']) . ' (' . $m['duration_min'] . ' min, ' . $m['timezone'] . ')';
        $where = self::where($m);
        $ics = Ics::invite($m, $organizer, array_map(fn($i) => ['name' => $i['name'], 'email' => $i['email']], $invitees), self::agendaText($agenda) . "\n\n" . url('meetings/' . $m['id']));
        $failed = 0;
        foreach ($invitees as $i) {
            if ((int)$i['id'] === (int)$m['organizer_id']) continue;
            if ($m['sent_email']) {
                $body = '<p>Hi ' . e(explode(' ', $i['name'])[0]) . ', you’re invited to <b>' . e($m['title']) . '</b>.</p>'
                    . '<p><span style="color:#7B8794">When</span> ' . e($when) . '<br><span style="color:#7B8794">Where</span> ' . (preg_match('~^https?://~', $where) ? '<a href="' . e($where) . '">' . e($where) . '</a>' : e($where)) . '<br><span style="color:#7B8794">Organiser</span> ' . e($organizer['name']) . '</p>'
                    . '<p style="margin-bottom:0"><b>Agenda</b></p>' . self::agendaHtml($agenda)
                    . ($i['status'] === 'invited' ? '<p style="color:#4A5663;font-size:14px">You’ll also get a separate email to set up your account, so you can add minutes after the meeting.</p>' : '')
                    . '<p style="color:#4A5663;font-size:14px">After the meeting, use the button below to add minutes and see your action items.</p>';
                $html = Notifier::layout('Invitation: ' . $m['title'], $body, 'Open meeting', url('meetings/' . $m['id']), $orgName);
                $atts = $calendarSent ? [] : [['name' => 'invite.ics', 'mime' => 'text/calendar; method=REQUEST; charset=UTF-8', 'content' => $ics]];
                $oid = Notifier::email($orgId, $i['email'], $i['name'], 'Invitation: ' . $m['title'] . ' · ' . fmt_date($m['meeting_date'], false) . ', ' . fmt_time($m['start_time']), $html, $atts);
                if (DB::value('SELECT status FROM outbox WHERE id = ?', [$oid]) !== 'sent') $failed++;
            }
            if ($m['sent_whatsapp'] && $i['phone']) {
                Notifier::whatsapp($orgId, (string)$i['phone'], $i['name'], "*{$m['title']}*\n📅 $when\n📍 $where\n\nAgenda:\n" . self::agendaText($agenda) . "\n\n" . url('meetings/' . $m['id']));
            }
            Notifier::inApp((int)$i['id'], $organizer['name'] . ' invited you to "' . $m['title'] . '" on ' . fmt_date($m['meeting_date'], false), 'meetings/' . $m['id']);
        }
        if ($failed) $warnings[] = plural($failed, 'invite email') . ' couldn’t be sent yet and will be retried automatically. Check Integrations → Email.';
        DB::update('meetings', ['invite_sent_at' => now_utc()], ['id' => $m['id']]);
        return $warnings;
    }
}
