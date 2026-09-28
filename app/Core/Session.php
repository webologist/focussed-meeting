<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = str_starts_with((string)config('app.url'), 'https://');
        session_name('fm_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '28800');
        $dir = STORAGE_PATH . '/cache/sessions';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
        session_start();

        // Idle timeout: 8 hours
        $now = time();
        if (isset($_SESSION['_last']) && $now - $_SESSION['_last'] > 28800) {
            session_unset();
            session_regenerate_id(true);
        }
        $_SESSION['_last'] = $now;
    }
}
