<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id) {
                self::$user = DB::one(
                    "SELECT u.*, o.name AS org_name, o.timezone AS org_timezone, o.plan AS org_plan, s.timezone AS timezone
                       FROM users u
                       JOIN organizations o ON o.id = u.org_id
                  LEFT JOIN user_settings s ON s.user_id = u.id
                      WHERE u.id = ? AND u.status = 'active'", [$id]);
                if (!self::$user) unset($_SESSION['user_id']);
            }
        }
        return self::$user;
    }

    public static function check(): bool { return self::user() !== null; }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        unset($_SESSION['_csrf']);
        self::$loaded = false;
        DB::update('users', ['last_login_at' => now_utc()], ['id' => $userId]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
        self::$loaded = true;
    }

    public static function refresh(): void { self::$loaded = false; self::$user = null; }

    /** Returns true when this email+IP has had too many failed attempts in the last 15 minutes. */
    public static function throttled(string $email): bool
    {
        $n = (int)DB::value("SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip = ?) AND created_at > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)",
            [mb_strtolower($email), client_ip()]);
        return $n >= 8;
    }

    public static function recordFailure(string $email): void
    {
        DB::insert('login_attempts', ['email' => mb_strtolower($email), 'ip' => client_ip(), 'created_at' => now_utc()]);
    }

    public static function clearFailures(string $email): void
    {
        DB::run('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower($email)]);
    }
}
