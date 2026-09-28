<?php
declare(strict_types=1);

namespace App\Core;

/** AES-256-GCM encryption for secrets at rest (SMTP passwords, OAuth refresh tokens). */
final class Crypto
{
    private static function key(): string
    {
        $hex = (string)config('app.key');
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new \RuntimeException('app.key must be 64 hex characters. See config/config.sample.php.');
        }
        return hex2bin($hex);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $encoded): ?string
    {
        if (!$encoded) return null;
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function encryptArray(array $data): string { return self::encrypt(json_encode($data)); }

    public static function decryptArray(?string $encoded): array
    {
        $p = self::decrypt($encoded);
        $d = $p ? json_decode($p, true) : null;
        return is_array($d) ? $d : [];
    }

    /** Random URL-safe token; store only its hash. */
    public static function token(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }

    public static function hash(string $token): string { return hash('sha256', $token); }
}
