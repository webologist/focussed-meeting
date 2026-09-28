<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Sends email and WhatsApp messages through the company's own connections.
 * Every message is written to the outbox first; failures are retried by cron/run.php.
 */
final class Notifier
{
    /** Queue and try to send an email right away. $orgId = null for platform/system emails. */
    public static function email(?int $orgId, string $to, string $toName, string $subject, string $html, array $attachments = [], bool $sendNow = true): int
    {
        $id = DB::insert('outbox', [
            'org_id' => $orgId, 'channel' => 'email', 'to_address' => $to, 'to_name' => $toName, 'subject' => $subject,
            'body_html' => $html, 'body_text' => self::toText($html),
            'attachments' => $attachments ? json_encode(array_map(fn($a) => ['name' => $a['name'], 'mime' => $a['mime'], 'content_base64' => base64_encode($a['content'])], $attachments)) : null,
            'status' => 'pending', 'created_at' => now_utc(),
        ]);
        if ($sendNow) self::deliver($id);
        return $id;
    }

    public static function whatsapp(int $orgId, string $to, string $toName, string $text, bool $sendNow = true): ?int
    {
        if (!Integrations::connected($orgId, 'whatsapp') || !preg_match('/\d{10,}/', preg_replace('/\D/', '', $to))) return null;
        $id = DB::insert('outbox', ['org_id' => $orgId, 'channel' => 'whatsapp', 'to_address' => $to, 'to_name' => $toName, 'body_text' => $text, 'status' => 'pending', 'created_at' => now_utc()]);
        if ($sendNow) self::deliver($id);
        return $id;
    }

    /** In-app notification (bell). */
    public static function inApp(int $userId, string $body, ?string $link = null): void
    {
        DB::insert('notifications', ['user_id' => $userId, 'body' => mb_substr($body, 0, 490), 'link' => $link, 'created_at' => now_utc()]);
    }

    /** Which SMTP settings to use for a company. Returns null when nothing is available. */
    public static function smtpConfig(?int $orgId): ?array
    {
        if ($orgId) {
            $int = Integrations::get($orgId, 'smtp');
            if ($int) return $int['config'];
            if (!config('mail.fallback_for_companies')) return null;
        }
        $c = config('mail');
        return !empty($c['host']) ? $c : null;
    }

    public static function deliver(int $outboxId): bool
    {
        $m = DB::one('SELECT * FROM outbox WHERE id = ?', [$outboxId]);
        if (!$m || $m['status'] === 'sent') return true;
        try {
            if ($m['channel'] === 'email') {
                $cfg = self::smtpConfig($m['org_id'] ? (int)$m['org_id'] : null);
                if (!$cfg) throw new \RuntimeException('No email connected. An admin can connect one in Integrations.');
                $atts = [];
                foreach (json_decode((string)$m['attachments'], true) ?: [] as $a) $atts[] = ['name' => $a['name'], 'mime' => $a['mime'], 'content' => base64_decode($a['content_base64'])];
                (new SmtpMailer($cfg))->send(['to' => $m['to_address'], 'to_name' => $m['to_name'], 'subject' => $m['subject'], 'html' => $m['body_html'], 'text' => $m['body_text'], 'attachments' => $atts]);
            } else {
                WhatsApp::send((int)$m['org_id'], $m['to_address'], (string)$m['body_text']);
            }
            DB::run('UPDATE outbox SET status = "sent", sent_at = UTC_TIMESTAMP(), attempts = attempts + 1, last_error = NULL WHERE id = ?', [$outboxId]);
            return true;
        } catch (\Throwable $e) {
            DB::run('UPDATE outbox SET status = IF(attempts + 1 >= 5, "failed", "pending"), attempts = attempts + 1, last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 490), $outboxId]);
            logger('Delivery failed', ['outbox' => $outboxId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public static function toText(string $html): string
    {
        $t = preg_replace(['#<br\s*/?>#i', '#</(p|div|h\d|li|tr)>#i', '#<li[^>]*>#i'], ["\n", "\n", '• '], $html);
        $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $t)));
    }

    /** Branded HTML email shell. $bodyHtml must already be escaped. */
    public static function layout(string $heading, string $bodyHtml, ?string $ctaText = null, ?string $ctaUrl = null, ?string $orgName = null): string
    {
        $cta = $ctaText && $ctaUrl ? '<p style="margin:24px 0"><a href="' . e($ctaUrl) . '" style="background:#1F6F78;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:600;display:inline-block">' . e($ctaText) . '</a></p>' : '';
        $brand = e($orgName ?: (string)config('app.name'));
        return '<!doctype html><html><body style="margin:0;background:#F3F5F7;font-family:Segoe UI,Arial,sans-serif;color:#16202A">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F3F5F7;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #D9DEE4;border-radius:12px">'
            . '<tr><td style="padding:20px 28px;border-bottom:1px solid #D9DEE4;font-weight:700;font-size:15px;color:#1F6F78">' . $brand . '</td></tr>'
            . '<tr><td style="padding:24px 28px;font-size:15px;line-height:1.55">'
            . '<h1 style="font-size:20px;margin:0 0 14px">' . e($heading) . '</h1>' . $bodyHtml . $cta
            . '</td></tr><tr><td style="padding:14px 28px;border-top:1px solid #D9DEE4;font-size:12px;color:#7B8794">Sent by ' . e((string)config('app.name')) . ' · ' . e((string)config('app.url')) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
