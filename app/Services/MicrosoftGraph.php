<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Microsoft Graph: creates an Outlook calendar event with a Teams meeting.
 * Each company connects its own Microsoft 365 work account via OAuth.
 */
final class MicrosoftGraph
{
    public const SCOPES = 'openid email offline_access User.Read Calendars.ReadWrite OnlineMeetings.ReadWrite';

    public static function configured(): bool { return (string)config('microsoft.client_id') !== '' && (string)config('microsoft.client_secret') !== ''; }

    public static function redirectUri(): string { return url('integrations/microsoft/callback'); }

    private static function base(): string { return 'https://login.microsoftonline.com/' . rawurlencode((string)config('microsoft.tenant', 'common')) . '/oauth2/v2.0'; }

    public static function authUrl(string $state): string
    {
        return self::base() . '/authorize?' . http_build_query([
            'client_id' => config('microsoft.client_id'), 'response_type' => 'code', 'redirect_uri' => self::redirectUri(),
            'response_mode' => 'query', 'scope' => self::SCOPES, 'state' => $state, 'prompt' => 'select_account',
        ]);
    }

    public static function exchange(string $code): array
    {
        $r = Http::request('POST', self::base() . '/token', ['form' => [
            'client_id' => config('microsoft.client_id'), 'client_secret' => config('microsoft.client_secret'),
            'code' => $code, 'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code', 'scope' => self::SCOPES,
        ]]);
        if ($r['status'] !== 200 || empty($r['body']['refresh_token'])) {
            throw new \RuntimeException('Microsoft did not return access. ' . (is_array($r['body']) ? ($r['body']['error_description'] ?? '') : ''));
        }
        $me = Http::request('GET', 'https://graph.microsoft.com/v1.0/me', ['bearer' => $r['body']['access_token']]);
        $email = is_array($me['body']) ? (string)($me['body']['mail'] ?? $me['body']['userPrincipalName'] ?? '') : '';
        return [[
            'refresh_token' => $r['body']['refresh_token'],
            'access_token'  => $r['body']['access_token'],
            'expires_at'    => time() + (int)($r['body']['expires_in'] ?? 3500) - 60,
            'email'         => $email,
        ], $email];
    }

    private static function accessToken(int $orgId): string
    {
        $int = Integrations::get($orgId, 'microsoft');
        if (!$int) throw new \RuntimeException('Microsoft Teams is not connected for this company.');
        $c = $int['config'];
        if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time()) return $c['access_token'];
        $r = Http::request('POST', self::base() . '/token', ['form' => [
            'client_id' => config('microsoft.client_id'), 'client_secret' => config('microsoft.client_secret'),
            'refresh_token' => $c['refresh_token'] ?? '', 'grant_type' => 'refresh_token', 'scope' => self::SCOPES,
        ]]);
        if ($r['status'] !== 200 || empty($r['body']['access_token'])) {
            Integrations::markError($orgId, 'microsoft', 'Access expired. Reconnect Microsoft.');
            throw new \RuntimeException('Microsoft access expired. An admin needs to reconnect Teams in Integrations.');
        }
        $c['access_token'] = $r['body']['access_token'];
        if (!empty($r['body']['refresh_token'])) $c['refresh_token'] = $r['body']['refresh_token'];
        $c['expires_at'] = time() + (int)($r['body']['expires_in'] ?? 3500) - 60;
        Integrations::updateConfig($orgId, 'microsoft', $c);
        return $c['access_token'];
    }

    /** @return array{id:string, join_url:string} */
    public static function createEvent(int $orgId, array $m, array $attendees, string $descriptionHtml): array
    {
        $token = self::accessToken($orgId);
        $start = new \DateTime($m['meeting_date'] . ' ' . $m['start_time'] . ':00', new \DateTimeZone($m['timezone']));
        $end = (clone $start)->modify('+' . (int)$m['duration_min'] . ' minutes');
        $body = [
            'subject' => $m['title'],
            'body'    => ['contentType' => 'HTML', 'content' => $descriptionHtml],
            'start'   => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => self::windowsTz($m['timezone'])],
            'end'     => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => self::windowsTz($m['timezone'])],
            'attendees' => array_map(fn($a) => ['emailAddress' => ['address' => $a['email'], 'name' => $a['name']], 'type' => 'required'], $attendees),
            'isOnlineMeeting' => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
        ];
        $r = Http::request('POST', 'https://graph.microsoft.com/v1.0/me/events', ['bearer' => $token, 'json' => $body]);
        if ($r['status'] >= 300) {
            $msg = is_array($r['body']) ? ($r['body']['error']['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException('Microsoft Graph: ' . $msg);
        }
        return ['id' => (string)$r['body']['id'], 'join_url' => (string)($r['body']['onlineMeeting']['joinUrl'] ?? $r['body']['webLink'] ?? '')];
    }

    public static function cancelEvent(int $orgId, string $eventId): void
    {
        try {
            Http::request('POST', 'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode($eventId) . '/cancel', ['bearer' => self::accessToken($orgId), 'json' => ['comment' => 'Meeting cancelled']]);
        } catch (\Throwable $e) { logger('Microsoft cancel failed: ' . $e->getMessage()); }
    }

    /** Graph accepts IANA names in most tenants; map the common ones to Windows names for safety. */
    private static function windowsTz(string $iana): string
    {
        return [
            'Asia/Kolkata' => 'India Standard Time', 'Asia/Calcutta' => 'India Standard Time', 'Asia/Dubai' => 'Arabian Standard Time',
            'Asia/Singapore' => 'Singapore Standard Time', 'Europe/London' => 'GMT Standard Time', 'America/New_York' => 'Eastern Standard Time',
            'America/Los_Angeles' => 'Pacific Standard Time', 'UTC' => 'UTC', 'Australia/Sydney' => 'AUS Eastern Standard Time',
        ][$iana] ?? $iana;
    }
}
