<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Google Calendar API with Meet conferencing.
 * Each company connects its own Google Workspace / Gmail account via OAuth.
 */
final class GoogleCalendar
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.events';

    public static function configured(): bool { return (string)config('google.client_id') !== '' && (string)config('google.client_secret') !== ''; }

    public static function redirectUri(): string { return url('integrations/google/callback'); }

    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => config('google.client_id'),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /** Exchange the OAuth code. Returns [config, emailLabel]. */
    public static function exchange(string $code): array
    {
        $r = Http::request('POST', 'https://oauth2.googleapis.com/token', ['form' => [
            'code' => $code, 'client_id' => config('google.client_id'), 'client_secret' => config('google.client_secret'),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
        ]]);
        if ($r['status'] !== 200 || empty($r['body']['refresh_token'])) {
            throw new \RuntimeException('Google did not return access. ' . (is_array($r['body']) ? ($r['body']['error_description'] ?? $r['body']['error'] ?? '') : ''));
        }
        $email = '';
        if (!empty($r['body']['id_token'])) {
            $parts = explode('.', $r['body']['id_token']);
            $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
            $email = (string)($payload['email'] ?? '');
        }
        return [[
            'refresh_token' => $r['body']['refresh_token'],
            'access_token'  => $r['body']['access_token'],
            'expires_at'    => time() + (int)($r['body']['expires_in'] ?? 3500) - 60,
            'email'         => $email,
        ], $email];
    }

    private static function accessToken(int $orgId): string
    {
        $int = Integrations::get($orgId, 'google');
        if (!$int) throw new \RuntimeException('Google is not connected for this company.');
        $c = $int['config'];
        if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time()) return $c['access_token'];
        $r = Http::request('POST', 'https://oauth2.googleapis.com/token', ['form' => [
            'client_id' => config('google.client_id'), 'client_secret' => config('google.client_secret'),
            'refresh_token' => $c['refresh_token'] ?? '', 'grant_type' => 'refresh_token',
        ]]);
        if ($r['status'] !== 200 || empty($r['body']['access_token'])) {
            Integrations::markError($orgId, 'google', 'Access expired. Reconnect Google.');
            throw new \RuntimeException('Google access expired. An admin needs to reconnect Google in Integrations.');
        }
        $c['access_token'] = $r['body']['access_token'];
        $c['expires_at'] = time() + (int)($r['body']['expires_in'] ?? 3500) - 60;
        Integrations::updateConfig($orgId, 'google', $c);
        return $c['access_token'];
    }

    /**
     * Create a calendar event with a Google Meet link. Google emails calendar invites to attendees.
     * @return array{id:string, join_url:string}
     */
    public static function createEvent(int $orgId, array $m, array $attendeeEmails, string $description): array
    {
        $token = self::accessToken($orgId);
        $start = new \DateTime($m['meeting_date'] . ' ' . $m['start_time'] . ':00', new \DateTimeZone($m['timezone']));
        $end = (clone $start)->modify('+' . (int)$m['duration_min'] . ' minutes');
        $body = [
            'summary'     => $m['title'],
            'description' => $description,
            'start'       => ['dateTime' => $start->format('c'), 'timeZone' => $m['timezone']],
            'end'         => ['dateTime' => $end->format('c'), 'timeZone' => $m['timezone']],
            'attendees'   => array_map(fn($e) => ['email' => $e], $attendeeEmails),
            'conferenceData' => ['createRequest' => ['requestId' => bin2hex(random_bytes(8)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]],
            'reminders'   => ['useDefault' => true],
        ];
        $r = Http::request('POST', 'https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=all', ['bearer' => $token, 'json' => $body]);
        if ($r['status'] >= 300) {
            $msg = is_array($r['body']) ? ($r['body']['error']['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException('Google Calendar: ' . $msg);
        }
        return ['id' => (string)$r['body']['id'], 'join_url' => (string)($r['body']['hangoutLink'] ?? $r['body']['htmlLink'] ?? '')];
    }

    public static function cancelEvent(int $orgId, string $eventId): void
    {
        try {
            Http::request('DELETE', 'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($eventId) . '?sendUpdates=all', ['bearer' => self::accessToken($orgId)]);
        } catch (\Throwable $e) { logger('Google cancel failed: ' . $e->getMessage()); }
    }
}
