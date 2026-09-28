<?php
declare(strict_types=1);

namespace App\Services;

/** iCalendar (.ics) invite so the meeting lands in any calendar app. */
final class Ics
{
    public static function invite(array $m, array $organizer, array $attendees, string $description, string $method = 'REQUEST'): string
    {
        $start = new \DateTime($m['starts_at_utc'], new \DateTimeZone('UTC'));
        $end = (clone $start)->modify('+' . (int)$m['duration_min'] . ' minutes');
        $host = parse_url((string)config('app.url'), PHP_URL_HOST) ?: 'focusedmeetings';
        $lines = [
            'BEGIN:VCALENDAR', 'PRODID:-//Focused Meetings//EN', 'VERSION:2.0', 'CALSCALE:GREGORIAN', "METHOD:$method",
            'BEGIN:VEVENT',
            'UID:meeting-' . $m['id'] . '@' . $host,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . self::esc($m['title']),
            'DESCRIPTION:' . self::esc($description),
            'LOCATION:' . self::esc($m['platform'] === 'inperson' ? (string)$m['location'] : (string)$m['join_url']),
            'ORGANIZER;CN=' . self::esc($organizer['name']) . ':mailto:' . $organizer['email'],
        ];
        foreach ($attendees as $a) {
            $lines[] = 'ATTENDEE;CN=' . self::esc($a['name']) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $a['email'];
        }
        if ($m['platform'] !== 'inperson' && !empty($m['join_url'])) $lines[] = 'URL:' . $m['join_url'];
        $lines[] = 'STATUS:' . ($method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'SEQUENCE:' . ($method === 'CANCEL' ? '1' : '0');
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-PT15M';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:Reminder';
        $lines[] = 'END:VALARM';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function esc(string $s): string { return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $s); }

    private static function fold(string $line): string
    {
        if (strlen($line) <= 74) return $line;
        $out = '';
        while (strlen($line) > 74) { $cut = 74; while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--; $out .= substr($line, 0, $cut) . "\r\n "; $line = substr($line, $cut); }
        return $out . $line;
    }
}
