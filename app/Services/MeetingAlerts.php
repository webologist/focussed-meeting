<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * In-app meeting alerts. The browser asks for them every half minute (NotificationController::poll) and shows each one
 * as a pop-up. Every alert is also added to the Alerts list (bell), and recorded in meeting_alerts so it appears only once.
 *
 *  - "start": N minutes before a meeting starts (default 10, set meetings.alert_before_start_min in config.php), until 5 minutes after it began
 *  - "end":   5 minutes before the meeting's To time
 *
 * Who gets them: everyone invited (and the host) who hasn't answered "No" to the invite, for meetings that are still scheduled.
 */
final class MeetingAlerts
{
    public const END_LEAD_MIN = 5;
    public const START_GRACE_MIN = 5;
    /** A meeting this short gets only the start alert (its end alert would fire at the same moment). */
    public const MIN_MINUTES_FOR_END_ALERT = 10;

    public static function startLeadMinutes(): int
    {
        return max(1, min(120, (int)config('meetings.alert_before_start_min', 10)));
    }

    /**
     * Alerts that became due for this person since the last check. Each one is returned once, ever.
     * @return array<int, array{key:string,kind:string,meeting_id:int,title:string,text:string,url:string,join:?string,when:string}>
     */
    public static function poll(int $userId, int $orgId): array
    {
        $lead = self::startLeadMinutes();
        $rows = DB::all(
            "SELECT m.id, m.title, m.organizer_id, m.platform, m.join_url, m.location, m.duration_min, m.start_time, m.end_time,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), m.starts_at_utc) AS to_start,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), DATE_ADD(m.starts_at_utc, INTERVAL m.duration_min MINUTE)) AS to_end
               FROM meetings m JOIN meeting_invitees i ON i.meeting_id = m.id AND i.user_id = :u
              WHERE m.org_id = :o AND m.status = 'scheduled' AND i.rsvp <> 'no'
                AND m.starts_at_utc <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL $lead MINUTE)
                AND DATE_ADD(m.starts_at_utc, INTERVAL m.duration_min MINUTE) > UTC_TIMESTAMP()
              ORDER BY m.starts_at_utc", ['u' => $userId, 'o' => $orgId]);

        $out = [];
        foreach ($rows as $m) {
            foreach (self::dueKinds($m) as $kind) {
                if (!self::claim((int)$m['id'], $userId, $kind)) continue;
                $text = self::text($m, $kind, $userId);
                Notifier::inApp($userId, $text, 'meetings/' . $m['id']);
                $out[] = [
                    'key' => 'm' . $m['id'] . '-' . $kind, 'kind' => $kind, 'meeting_id' => (int)$m['id'], 'title' => (string)$m['title'], 'text' => $text,
                    'url' => path('meetings/' . $m['id']),
                    'join' => $m['platform'] !== 'inperson' && !empty($m['join_url']) ? (string)$m['join_url'] : null,
                    'when' => fmt_range($m),
                ];
            }
        }
        return $out;
    }

    /** Which alerts are due right now for a meeting row that has to_start / to_end (seconds from now). */
    public static function dueKinds(array $m, ?int $lead = null): array
    {
        $lead ??= self::startLeadMinutes();
        $toStart = (int)$m['to_start'];
        $toEnd = (int)$m['to_end'];
        $kinds = [];
        if ($toStart <= $lead * 60 && $toStart > -self::START_GRACE_MIN * 60) $kinds[] = 'start';
        if ($toEnd <= self::END_LEAD_MIN * 60 && $toEnd > 0 && (int)$m['duration_min'] >= self::MIN_MINUTES_FOR_END_ALERT) $kinds[] = 'end';
        return $kinds;
    }

    public static function text(array $m, string $kind, int $userId): string
    {
        $title = '"' . $m['title'] . '"';
        if ($kind === 'end') {
            $mins = max(1, (int)ceil((int)$m['to_end'] / 60));
            return $title . ' ends in ' . ($mins === 1 ? '1 minute' : $mins . ' minutes') . ' (' . fmt_time(meeting_end($m)) . ').'
                . ((int)$m['organizer_id'] === $userId ? ' Wrap up, then mark it as over to open the minutes.' : ' Time to wrap up.');
        }
        $toStart = (int)$m['to_start'];
        $where = $m['platform'] === 'inperson' ? ($m['location'] ? ' at ' . $m['location'] : ' (in person)') : ' on ' . platform_label((string)$m['platform']);
        if ($toStart <= 0) return $title . ' is starting now' . $where . '.';
        $mins = (int)ceil($toStart / 60);
        return $title . ' starts in ' . ($mins === 1 ? '1 minute' : $mins . ' minutes') . ' (' . fmt_time($m['start_time']) . ')' . $where . '.';
    }

    /** True the first time this alert is recorded for this person. */
    private static function claim(int $meetingId, int $userId, string $kind): bool
    {
        return DB::run('INSERT IGNORE INTO meeting_alerts (meeting_id, user_id, kind, created_at) VALUES (?, ?, ?, ?)', [$meetingId, $userId, $kind, now_utc()])->rowCount() === 1;
    }
}
