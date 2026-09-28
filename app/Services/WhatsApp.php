<?php
declare(strict_types=1);

namespace App\Services;

/**
 * WhatsApp Business Cloud API (Meta). Optional, per company.
 * Business-initiated messages need an approved template; configure a generic template with one body variable {{1}}.
 */
final class WhatsApp
{
    public static function send(int $orgId, string $to, string $text): void
    {
        $int = Integrations::get($orgId, 'whatsapp');
        if (!$int) throw new \RuntimeException('WhatsApp is not connected.');
        $c = $int['config'];
        $to = preg_replace('/\D+/', '', $to);
        if (strlen($to) < 10) throw new \RuntimeException('Recipient has no valid WhatsApp number.');
        $url = 'https://graph.facebook.com/v20.0/' . rawurlencode((string)$c['phone_number_id']) . '/messages';
        $payload = !empty($c['template'])
            ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
                'name' => $c['template'], 'language' => ['code' => $c['language'] ?? 'en'],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($text, 0, 1000)]]]]]]
            : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => mb_substr($text, 0, 4000)]];
        $r = Http::request('POST', $url, ['bearer' => $c['access_token'] ?? '', 'json' => $payload]);
        if ($r['status'] >= 300) {
            $msg = is_array($r['body']) ? ($r['body']['error']['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException('WhatsApp: ' . $msg);
        }
    }
}
