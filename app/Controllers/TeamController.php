<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Audit;

final class TeamController
{
    public function index(): void
    {
        $members = DB::all("SELECT u.*, (SELECT COUNT(*) FROM actions a WHERE a.owner_id = u.id AND a.status = 'open') AS open_items FROM users u WHERE u.org_id = ? ORDER BY FIELD(u.status,'active','invited','disabled'), u.role = 'participant', u.name", [org_id()]);
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
        if ($id === uid()) { flash('error', 'You can’t turn off your own access.'); redirect('team'); }
        $status = input('status') === 'disabled' ? 'disabled' : ($u['password_hash'] ? 'active' : 'invited');
        if ($status === 'disabled' && $u['role'] === 'admin' && self::adminCount() <= 1) { flash('error', 'There must be at least one active admin.'); redirect('team'); }
        DB::update('users', ['status' => $status, 'updated_at' => now_utc()], ['id' => $id]);
        Audit::log('team.status', 'user', $id, ['status' => $status]);
        flash('success', $status === 'disabled' ? $u['name'] . ' can no longer sign in.' : $u['name'] . ' has access again.');
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
        $u = DB::one('SELECT * FROM users WHERE id = ? AND org_id = ?', [$id, org_id()]);
        if (!$u) abort(404);
        return $u;
    }

    private static function adminCount(): int
    {
        return (int)DB::value("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'admin' AND status = 'active'", [org_id()]);
    }
}
