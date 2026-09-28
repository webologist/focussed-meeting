<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Services\Notifier;

final class AuthController
{
    public function showRegister(): void
    {
        if (!config('app.allow_signups')) abort(403, 'New sign-ups are closed. Ask your company admin to invite you.');
        echo view('auth/register', ['title' => 'Create your account', 'errors' => $_SESSION['_errors'] ?? []], 'layouts/auth');
        unset($_SESSION['_errors']); clear_old();
    }

    public function register(): void
    {
        if (!config('app.allow_signups')) abort(403);
        $d = ['company' => (string)input('company'), 'name' => (string)input('name'), 'email' => mb_strtolower((string)input('email')), 'phone' => (string)input('phone')];
        $pw = (string)($_POST['password'] ?? '');
        $errors = [];
        if (mb_strlen($d['company']) < 2) $errors['company'] = 'Enter your company or team name.';
        if (mb_strlen($d['name']) < 2) $errors['name'] = 'Enter your full name.';
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid work email.';
        elseif (DB::value('SELECT 1 FROM users WHERE email = ?', [$d['email']])) $errors['email'] = 'An account with this email already exists. Sign in instead.';
        if ($e = self::passwordProblem($pw)) $errors['password'] = $e;
        if (empty($_POST['terms'])) $errors['terms'] = 'Please accept the terms to continue.';
        if ($errors) { $_SESSION['_errors'] = $errors; keep_old($d); redirect('register'); }

        $userId = DB::transaction(function () use ($d, $pw) {
            $slug = self::uniqueSlug($d['company']);
            $orgId = DB::insert('organizations', ['name' => mb_substr($d['company'], 0, 150), 'slug' => $slug, 'timezone' => config('app.timezone', 'Asia/Kolkata'), 'plan' => 'free', 'created_at' => now_utc()]);
            $uid = DB::insert('users', [
                'org_id' => $orgId, 'name' => mb_substr($d['name'], 0, 120), 'email' => $d['email'], 'phone' => $d['phone'] ?: null,
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'admin', 'status' => 'active',
                'email_verified_at' => config('app.require_email_verification') ? null : now_utc(), 'created_at' => now_utc(),
            ]);
            DB::insert('user_settings', ['user_id' => $uid]);
            DB::insert('audit_log', ['org_id' => $orgId, 'user_id' => $uid, 'event' => 'company.registered', 'ip' => client_ip(), 'created_at' => now_utc()]);
            return $uid;
        });
        Auth::login($userId);
        if (config('app.require_email_verification')) {
            self::sendVerification($userId);
            redirect('verify-email');
        }
        flash('success', 'Welcome! Your company workspace is ready.');
        redirect('dashboard');
    }

    public function showLogin(): void
    {
        echo view('auth/login', ['title' => 'Sign in'], 'layouts/auth');
        clear_old();
    }

    public function login(): void
    {
        $email = mb_strtolower((string)input('email'));
        $pw = (string)($_POST['password'] ?? '');
        if (Auth::throttled($email)) { flash('error', 'Too many attempts. Wait 15 minutes and try again, or reset your password.'); keep_old(['email' => $email]); redirect('login'); }
        $u = DB::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$u || !$u['password_hash'] || !password_verify($pw, $u['password_hash'])) {
            Auth::recordFailure($email);
            flash('error', 'That email and password don’t match. Check both and try again.');
            keep_old(['email' => $email]);
            redirect('login');
        }
        if ($u['status'] === 'disabled') { flash('error', 'Your access has been turned off. Contact your company admin.'); redirect('login'); }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) DB::update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)], ['id' => $u['id']]);
        Auth::clearFailures($email);
        Auth::login((int)$u['id']);
        $to = $_SESSION['_intended'] ?? '';
        unset($_SESSION['_intended']);
        if ($to && str_starts_with($to, '/') && !str_starts_with($to, '//')) { header('Location: ' . $to); exit; }
        redirect('dashboard');
    }

    public function logout(): void
    {
        Auth::logout();
        flash('info', 'You’ve been signed out.');
        redirect('login');
    }

    public function verifyNotice(): void
    {
        if (!empty(user()['email_verified_at'])) redirect('dashboard');
        echo view('auth/verify-notice', ['title' => 'Verify your email'], 'layouts/auth');
    }

    public function resendVerification(): void
    {
        self::sendVerification(uid());
        flash('success', 'We sent a new link to ' . user()['email'] . '.');
        redirect('verify-email');
    }

    public function verify(): void
    {
        $row = self::consumeToken((string)input('token'), 'verify');
        if (!$row) { flash('error', 'This verification link is invalid or has expired. Sign in to get a new one.'); redirect('login'); }
        DB::update('users', ['email_verified_at' => now_utc()], ['id' => $row['user_id']]);
        if (!Auth::check()) Auth::login((int)$row['user_id']);
        Auth::refresh();
        flash('success', 'Email verified. You’re all set.');
        redirect('dashboard');
    }

    public function showForgot(): void { echo view('auth/forgot', ['title' => 'Reset password'], 'layouts/auth'); }

    public function forgot(): void
    {
        $email = mb_strtolower((string)input('email'));
        $u = DB::one("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
        if ($u && !Auth::throttled($email)) {
            Auth::recordFailure($email); // also rate-limits reset emails
            $token = self::issueToken((int)$u['id'], 'reset', 3600);
            $html = Notifier::layout('Reset your password', '<p>Hi ' . e($u['name']) . ',</p><p>Use the button below to choose a new password. The link works for one hour.</p><p>If you didn’t ask for this, you can ignore this email.</p>', 'Choose a new password', url('reset-password', ['token' => $token]));
            Notifier::email(null, $u['email'], $u['name'], 'Reset your ' . config('app.name') . ' password', $html);
        }
        flash('success', 'If that email has an account, a reset link is on its way. Check your inbox.');
        redirect('login');
    }

    public function showReset(): void
    {
        $token = (string)input('token');
        if (!self::findToken($token, 'reset')) { flash('error', 'This reset link is invalid or has expired. Request a new one.'); redirect('forgot-password'); }
        echo view('auth/reset', ['title' => 'Choose a new password', 'token' => $token], 'layouts/auth');
    }

    public function reset(): void
    {
        $token = (string)input('token');
        $pw = (string)($_POST['password'] ?? '');
        if ($e = self::passwordProblem($pw)) { flash('error', $e); redirect('reset-password?token=' . urlencode($token)); }
        $row = self::consumeToken($token, 'reset');
        if (!$row) { flash('error', 'This reset link is invalid or has expired. Request a new one.'); redirect('forgot-password'); }
        DB::update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'email_verified_at' => DB::value('SELECT COALESCE(email_verified_at, UTC_TIMESTAMP()) FROM users WHERE id = ?', [$row['user_id']])], ['id' => $row['user_id']]);
        Auth::login((int)$row['user_id']);
        flash('success', 'Password updated. You’re signed in.');
        redirect('dashboard');
    }

    public function showAcceptInvite(): void
    {
        $token = (string)input('token');
        $row = self::findToken($token, 'invite');
        if (!$row) { flash('error', 'This invitation link is invalid or has expired. Ask your admin to resend it.'); redirect('login'); }
        $invitee = DB::one('SELECT u.*, o.name AS org_name FROM users u JOIN organizations o ON o.id = u.org_id WHERE u.id = ?', [$row['user_id']]);
        echo view('auth/accept-invite', ['title' => 'Join ' . $invitee['org_name'], 'token' => $token, 'invitee' => $invitee], 'layouts/auth');
    }

    public function acceptInvite(): void
    {
        $token = (string)input('token');
        $name = (string)input('name');
        $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($name) < 2) { flash('error', 'Enter your full name.'); redirect('accept-invite?token=' . urlencode($token)); }
        if ($e = self::passwordProblem($pw)) { flash('error', $e); redirect('accept-invite?token=' . urlencode($token)); }
        $row = self::consumeToken($token, 'invite');
        if (!$row) { flash('error', 'This invitation link is invalid or has expired.'); redirect('login'); }
        DB::update('users', ['name' => mb_substr($name, 0, 120), 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'status' => 'active', 'email_verified_at' => now_utc(), 'phone' => input('phone') ?: null, 'updated_at' => now_utc()], ['id' => $row['user_id']]);
        if (!DB::value('SELECT 1 FROM user_settings WHERE user_id = ?', [$row['user_id']])) DB::insert('user_settings', ['user_id' => $row['user_id']]);
        Auth::login((int)$row['user_id']);
        flash('success', 'Welcome aboard! Your account is ready.');
        redirect('dashboard');
    }

    // ---------------------------------------------------------------

    public static function passwordProblem(string $pw): ?string
    {
        if (mb_strlen($pw) < 8) return 'Use at least 8 characters for your password.';
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Use a mix of letters and numbers in your password.';
        return null;
    }

    public static function issueToken(int $userId, string $type, int $ttlSeconds): string
    {
        DB::run('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND type = ? AND used_at IS NULL', [$userId, $type]);
        $token = Crypto::token();
        DB::insert('auth_tokens', ['user_id' => $userId, 'type' => $type, 'token_hash' => Crypto::hash($token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds), 'created_at' => now_utc()]);
        return $token;
    }

    private static function findToken(string $token, string $type): ?array
    {
        if ($token === '') return null;
        return DB::one('SELECT * FROM auth_tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()', [Crypto::hash($token), $type]);
    }

    private static function consumeToken(string $token, string $type): ?array
    {
        $row = self::findToken($token, $type);
        if ($row) DB::update('auth_tokens', ['used_at' => now_utc()], ['id' => $row['id']]);
        return $row;
    }

    public static function sendVerification(int $userId): void
    {
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        $token = self::issueToken($userId, 'verify', 86400 * 3);
        $html = Notifier::layout('Confirm your email', '<p>Hi ' . e($u['name']) . ',</p><p>Confirm your email address to start using ' . e(config('app.name')) . '.</p>', 'Confirm email', url('verify', ['token' => $token]));
        Notifier::email(null, $u['email'], $u['name'], 'Confirm your email for ' . config('app.name'), $html);
    }

    public static function sendInvite(int $userId, string $inviterName, string $orgName): void
    {
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        $token = self::issueToken($userId, 'invite', 86400 * 7);
        $html = Notifier::layout('You’re invited to ' . $orgName, '<p>Hi ' . e($u['name']) . ',</p><p>' . e($inviterName) . ' invited you to join <b>' . e($orgName) . '</b> on ' . e(config('app.name')) . ', where your team plans meetings, records minutes and tracks action items.</p><p>The link works for 7 days.</p>', 'Accept invitation', url('accept-invite', ['token' => $token]), $orgName);
        Notifier::email(null, $u['email'], $u['name'], $inviterName . ' invited you to ' . $orgName, $html);
    }

    private static function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-') ?: 'company';
        $base = substr($base, 0, 60);
        $slug = $base; $i = 2;
        while (DB::value('SELECT 1 FROM organizations WHERE slug = ?', [$slug])) $slug = $base . '-' . $i++;
        return $slug;
    }
}
