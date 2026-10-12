<?php
declare(strict_types=1);

namespace App\Core;

/** Platform owner (super admin) session. Kept separate from company users. */
final class Owner
{
    private const IDLE_SECONDS = 7200; // owner sessions end after 2 hours without activity

    private static ?array $owner = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int)($_SESSION['owner_id'] ?? 0);
            if ($id && time() - (int)($_SESSION['owner_last'] ?? 0) > self::IDLE_SECONDS) {
                unset($_SESSION['owner_id'], $_SESSION['owner_last']);
                $id = 0;
            }
            if ($id) {
                self::$owner = DB::one('SELECT * FROM platform_admins WHERE id = ? AND password_hash IS NOT NULL', [$id]);
                if (self::$owner) $_SESSION['owner_last'] = time();
                else unset($_SESSION['owner_id']);
            }
        }
        return self::$owner;
    }

    public static function check(): bool { return self::user() !== null; }

    public static function id(): int { return (int)(self::user()['id'] ?? 0); }

    public static function login(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['owner_id'] = $id;
        $_SESSION['owner_last'] = time();
        unset($_SESSION['_csrf']);
        self::$loaded = false;
        DB::update('platform_admins', ['last_login_at' => now_utc()], ['id' => $id]);
    }

    public static function logout(): void
    {
        unset($_SESSION['owner_id'], $_SESSION['owner_last']);
        session_regenerate_id(true);
        self::$owner = null;
        self::$loaded = true;
    }

    public static function log(string $event, ?int $orgId = null, ?int $userId = null, array $meta = []): void
    {
        DB::insert('platform_audit', [
            'admin_id' => self::id() ?: null, 'event' => $event, 'org_id' => $orgId, 'user_id' => $userId,
            'meta' => $meta ? mb_substr((string)json_encode($meta), 0, 990) : null, 'ip' => client_ip(), 'created_at' => now_utc(),
        ]);
    }
}
