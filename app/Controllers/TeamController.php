<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Audit;
use App\Services\Notifier;

final class TeamController
{
    public function index(): void
    {
        $members = DB::all("SELECT u.*, (SELECT COUNT(*) FROM actions a WHERE a.owner_id = u.id AND a.status <> 'done') AS open_items FROM users u WHERE u.org_id = ? AND u.deleted_at IS NULL ORDER BY FIELD(u.status,'active','invited','disabled'), u.role = 'participant', u.name", [org_id()]);
        $org = DB::one('SELECT * FROM organizations WHERE id = ?', [org_id()]);
        echo view('team/index', ['members' => $members, 'org' => $org, 'title' => 'Team']);
    }

    public function invite(): void
    {
        $name = (string)input('name');
        $email = mb_strtolower((string)input('email'));
        $role = input('role') === 'admin' ? 'admin' : 'participant';
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter a name and a valid email.'); redirect('team'); }
        $existing = DB::one('SELECT id, org_id FROM users WHERE email = ?', [$email]);
        if ($existing) { flash('error', (int)$existing['org_id'] === org_id() ? 'That person is already on your team.' : 'That email already uses ' . config('app.name') . ' with another company.'); redirect('team'); }
        $seats = DB::value('SELECT seats_limit FROM organizations WHERE id = ?', [org_id()]);
        if ($seats && (int)DB::value("SELECT COUNT(*) FROM users WHERE org_id = ? AND status <> 'disabled'", [org_id()]) >= (int)$seats) { flash('error', 'Your plan’s seat limit is reached.'); redirect('team'); }
        $id = DB::insert('users', ['org_id' => org_id(), 'name' => mb_substr($name, 0, 120), 'email' => $email, 'phone' => input('phone') ?: null, 'role' => $role, 'status' => 'invited', 'invited_by' => uid(), 'created_at' => now_utc()]);
        DB::insert('user_settings', ['user_id' => $id]);
        AuthController::sendInvite($id, user()['name'], user()['org_name']);
        Audit::log('team.invited', 'user', $id, ['role' => $role]);
        flash('success', 'Invitation sent to ' . $email . '.');
        redirect('team');
    }

    /**
     * The admin creates the account directly and chooses the password. No email round trip: the person can sign in right away,
     * and the admin passes the password on privately. Use "Invite" instead to let the person choose their own password.
     */
    public function create(): void
    {
        $name = trim((string)input('name'));
        $email = mb_strtolower((string)input('email'));
        $role = input('role') === 'admin' ? 'admin' : 'participant';
        $phone = trim((string)input('phone', ''));
        $pw = (string)($_POST['password'] ?? ''); // not trimmed: spaces count
        $back = function (string $message) use ($name, $email, $role, $phone): never {
            $_SESSION['_team_new'] = ['name' => $name, 'email' => $email, 'role' => $role, 'phone' => $phone]; // never the password
            flash('error', $message);
            redirect('team#create-user');
        };
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $back('Enter a name and a valid email.');
        if ($e = AuthController::passwordProblem($pw)) $back($e);
        $existing = DB::one('SELECT id, org_id FROM users WHERE email = ?', [$email]);
        if ($existing) $back((int)$existing['org_id'] === org_id() ? 'That person is already on your team.' : 'That email already uses ' . config('app.name') . ' with another company.');
        $seats = DB::value('SELECT seats_limit FROM organizations WHERE id = ?', [org_id()]);
        if ($seats && (int)DB::value("SELECT COUNT(*) FROM users WHERE org_id = ? AND status <> 'disabled'", [org_id()]) >= (int)$seats) $back('Your plan’s seat limit is reached.');
        $id = DB::insert('users', [
            'org_id' => org_id(), 'name' => mb_substr($name, 0, 120), 'email' => $email, 'phone' => $phone !== '' ? mb_substr($phone, 0, 30) : null,
            'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active', 'email_verified_at' => now_utc(),
            'invited_by' => uid(), 'created_at' => now_utc(),
        ]);
        DB::insert('user_settings', ['user_id' => $id]);
        Audit::log('team.created', 'user', $id, ['role' => $role]);
        unset($_SESSION['_team_new']);
        flash('success', $name . ' can sign in now with ' . $email . ' and the password you set. Share it with them privately.');
        redirect('team');
    }

    /** The admin sets a new password for a member (for example when they forgot it). Pending invitations and reset links stop working. */
    public function setPassword(int $id): void
    {
        $u = self::member($id);
        if ($id === uid()) { flash('error', 'Change your own password from My account.'); redirect('team'); }
        $pw = (string)($_POST['password'] ?? '');
        if ($e = AuthController::passwordProblem($pw)) { flash('error', $e); redirect('team'); }
        $data = ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'email_verified_at' => $u['email_verified_at'] ?: now_utc(), 'updated_at' => now_utc()];
        if ($u['status'] === 'invited') $data['status'] = 'active'; // setting a password completes a pending invitation
        DB::update('users', $data, ['id' => $id]);
        DB::pdo()->prepare('DELETE FROM auth_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$id]);
        Audit::log('team.password_set', 'user', $id);
        Notifier::inApp($id, 'Your password was changed by an admin.', 'account');
        flash('success', 'New password set for ' . $u['name'] . '. Share it with them privately.' . ($u['status'] === 'disabled' ? ' They are still blocked.' : ''));
        redirect('team');
    }

    public function role(int $id): void
    {
        $u = self::member($id);
        $role = input('role') === 'admin' ? 'admin' : 'participant';
        if ($u['role'] === 'admin' && $role !== 'admin' && self::adminCount() <= 1) { flash('error', 'There must be at least one admin.'); redirect('team'); }
        DB::update('users', ['role' => $role, 'updated_at' => now_utc()], ['id' => $id]);
        Audit::log('team.role', 'user', $id, ['role' => $role]);
        flash('success', $u['name'] . ' is now ' . ($role === 'admin' ? 'an admin' : 'a participant') . '.');
        redirect('team');
    }

    public function status(int $id): void
    {
        $u = self::member($id);
        if ($id === uid()) { flash('error', 'You can’t block yourself.'); redirect('team'); }
        $status = input('status') === 'disabled' ? 'disabled' : ($u['password_hash'] ? 'active' : 'invited');
        if ($status === 'disabled' && $u['role'] === 'admin' && self::adminCount() <= 1) { flash('error', 'There must be at least one active admin.'); redirect('team'); }
        DB::update('users', ['status' => $status, 'updated_at' => now_utc()], ['id' => $id]);
        Audit::log('team.status', 'user', $id, ['status' => $status]);
        flash('success', $status === 'disabled' ? $u['name'] . ' is blocked and can no longer sign in.' : $u['name'] . ' is unblocked and has access again.');
        redirect('team');
    }

    /**
     * Removes a member. The row is kept (anonymised) so past meetings, minutes and
     * comments still show who did what; their open tasks move to another member.
     */
    public function destroy(int $id): void
    {
        $u = self::member($id);
        if ($id === uid()) { flash('error', 'You can’t delete yourself.'); redirect('team'); }
        if ($u['role'] === 'admin' && $u['status'] === 'active' && self::adminCount() <= 1) { flash('error', 'There must be at least one active admin.'); redirect('team'); }
        $openCount = (int)DB::value("SELECT COUNT(*) FROM actions WHERE owner_id = ? AND status <> 'done'", [$id]);
        $to = null;
        if ($openCount) {
            $to = DB::one("SELECT id, name FROM users WHERE id = ? AND org_id = ? AND status = 'active' AND deleted_at IS NULL", [(int)input('reassign_to'), org_id()]);
            if (!$to || (int)$to['id'] === $id) { flash('error', 'Choose who should take over ' . $u['name'] . '’s open tasks.'); redirect('team'); }
        }
        DB::pdo()->beginTransaction();
        try {
            if ($to) DB::pdo()->prepare("UPDATE actions SET owner_id = ?, updated_at = ? WHERE owner_id = ? AND status <> 'done'")->execute([$to['id'], now_utc(), $id]);
            DB::update('users', ['status' => 'disabled', 'deleted_at' => now_utc(), 'email' => 'deleted+' . $id . '@removed.invalid', 'password_hash' => null, 'phone' => null, 'updated_at' => now_utc()], ['id' => $id]);
            DB::pdo()->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([$id]);
            DB::pdo()->prepare('DELETE FROM reminders WHERE user_id = ?')->execute([$id]);
            DB::pdo()->commit();
        } catch (\Throwable $e) { DB::pdo()->rollBack(); throw $e; }
        Audit::log('team.deleted', 'user', $id, ['email' => $u['email'], 'reassigned_to' => $to['id'] ?? null, 'open_tasks' => $openCount]);
        flash('success', $u['name'] . ' was removed from the team.' . ($to ? ' ' . plural($openCount, 'open task') . ' moved to ' . $to['name'] . '.' : ''));
        redirect('team');
    }

    public function resend(int $id): void
    {
        $u = self::member($id);
        if ($u['status'] !== 'invited') redirect('team');
        AuthController::sendInvite($id, user()['name'], user()['org_name']);
        flash('success', 'Invitation sent again to ' . $u['email'] . '.');
        redirect('team');
    }

    public function company(): void
    {
        $name = (string)input('name');
        $tz = in_array(input('timezone'), AccountController::TIMEZONES, true) ? input('timezone') : 'Asia/Kolkata';
        if (mb_strlen($name) < 2) { flash('error', 'Enter the company name.'); redirect('team'); }
        DB::update('organizations', ['name' => mb_substr($name, 0, 150), 'timezone' => $tz, 'updated_at' => now_utc()], ['id' => org_id()]);
        flash('success', 'Company details saved.');
        redirect('team');
    }

    private static function member(int $id): array
    {
        $u = DB::one('SELECT * FROM users WHERE id = ? AND org_id = ? AND deleted_at IS NULL', [$id, org_id()]);
        if (!$u) abort(404);
        return $u;
    }

    private static function adminCount(): int
    {
        return (int)DB::value("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'admin' AND status = 'active'", [org_id()]);
    }
}
