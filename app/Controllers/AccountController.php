<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Audit;

final class AccountController
{
    public const TIMEZONES = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney', 'UTC'];

    public function index(): void
    {
        $settings = DB::one('SELECT * FROM user_settings WHERE user_id = ?', [uid()]);
        if (!$settings) { DB::insert('user_settings', ['user_id' => uid()]); $settings = DB::one('SELECT * FROM user_settings WHERE user_id = ?', [uid()]); }
        $stats = [
            'organised' => (int)DB::value('SELECT COUNT(*) FROM meetings WHERE organizer_id = ?', [uid()]),
            'assigned' => (int)DB::value('SELECT COUNT(*) FROM actions WHERE owner_id = ?', [uid()]),
            'done' => (int)DB::value("SELECT COUNT(*) FROM actions WHERE owner_id = ? AND status = 'done'", [uid()]),
        ];
        echo view('account/index', ['settings' => $settings, 'stats' => $stats, 'title' => 'My account']);
    }

    public function saveProfile(): void
    {
        $name = (string)input('name');
        $email = mb_strtolower((string)input('email'));
        if (mb_strlen($name) < 2) { flash('error', 'Enter your full name.'); redirect('account'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter a valid email.'); redirect('account'); }
        if ($email !== user()['email'] && DB::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, uid()])) { flash('error', 'Another account already uses that email.'); redirect('account'); }
        $changedEmail = $email !== user()['email'];
        DB::update('users', ['name' => mb_substr($name, 0, 120), 'email' => $email, 'phone' => input('phone') ?: null, 'title' => mb_substr((string)input('title'), 0, 120) ?: null,
            'email_verified_at' => $changedEmail && config('app.require_email_verification') ? null : user()['email_verified_at'], 'updated_at' => now_utc()], ['id' => uid()]);
        $tz = in_array(input('timezone'), self::TIMEZONES, true) ? input('timezone') : null;
        DB::update('user_settings', ['timezone' => $tz, 'signature' => mb_substr((string)input('signature'), 0, 1000) ?: null], ['user_id' => uid()]);
        Auth::refresh();
        if ($changedEmail && config('app.require_email_verification')) { AuthController::sendVerification(uid()); flash('info', 'Confirm your new email address using the link we just sent.'); redirect('verify-email'); }
        flash('success', 'Profile saved.');
        redirect('account');
    }

    public function savePreferences(): void
    {
        $bool = fn($k) => empty($_POST[$k]) ? 0 : 1;
        DB::update('user_settings', [
            'default_platform' => in_array(input('default_platform'), ['meet', 'teams', 'inperson'], true) ? input('default_platform') : 'meet',
            'default_duration' => in_array((int)input('default_duration'), [15, 30, 45, 60, 90, 120], true) ? (int)input('default_duration') : 30,
            'invite_email' => $bool('invite_email'), 'invite_whatsapp' => $bool('invite_whatsapp'),
            'reminder_days' => max(0, min(7, (int)input('reminder_days'))),
            'digest' => $bool('digest'), 'digest_time' => preg_match('/^\d{2}:\d{2}$/', (string)input('digest_time')) ? input('digest_time') : '09:00',
            'overdue_alerts' => $bool('overdue_alerts'), 'wa_reminders' => $bool('wa_reminders'),
        ], ['user_id' => uid()]);
        flash('success', 'Preferences saved.');
        redirect('account#preferences');
    }

    public function changePassword(): void
    {
        $u = DB::one('SELECT password_hash FROM users WHERE id = ?', [uid()]);
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$u['password_hash'])) { flash('error', 'Your current password isn’t right.'); redirect('account#security'); }
        $new = (string)($_POST['password'] ?? '');
        if ($e = AuthController::passwordProblem($new)) { flash('error', $e); redirect('account#security'); }
        DB::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'updated_at' => now_utc()], ['id' => uid()]);
        session_regenerate_id(true);
        Audit::log('user.password_changed', 'user', uid());
        flash('success', 'Password changed.');
        redirect('account#security');
    }
}
