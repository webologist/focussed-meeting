<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Owner;
use App\Services\Notifier;

/** Platform owner (super admin): every company, every user, plans and access. */
final class OwnerController
{
    public const PLANS = ['free' => 'Free', 'pro' => 'Pro', 'business' => 'Business'];

    public const EVENTS = [
        'owner.login' => 'Signed in', 'owner.logout' => 'Signed out', 'owner.password_set' => 'Set owner password', 'owner.password_changed' => 'Changed owner password',
        'owner.setup_link' => 'Issued owner setup link', 'company.plan' => 'Changed plan', 'company.suspended' => 'Suspended company', 'company.restored' => 'Restored company',
        'user.disabled' => 'Turned off user', 'user.active' => 'Restored user', 'user.invited' => 'Restored invited user', 'user.role' => 'Changed role', 'user.reset_link' => 'Created password link',
    ];

    // ---------------------------------------------------------------- sign in / set password

    public function showLogin(): void
    {
        if (Owner::check()) redirect('owner');
        echo view('owner/login', ['title' => 'Platform owner sign in'], 'layouts/auth');
        clear_old();
    }

    public function login(): void
    {
        $email = mb_strtolower((string)input('email'));
        $pw = (string)($_POST['password'] ?? '');
        $key = 'owner:' . $email;
        if (Auth::throttled($key)) { flash('error', 'Too many attempts. Wait 15 minutes and try again.'); keep_old(['email' => $email]); redirect('owner/login'); }
        $o = DB::one('SELECT * FROM platform_admins WHERE email = ?', [$email]);
        if (!$o || !$o['password_hash'] || !password_verify($pw, $o['password_hash'])) {
            Auth::recordFailure($key);
            flash('error', 'That email and password don’t match.');
            keep_old(['email' => $email]);
            redirect('owner/login');
        }
        if (password_needs_rehash($o['password_hash'], PASSWORD_DEFAULT)) DB::update('platform_admins', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)], ['id' => $o['id']]);
        Auth::clearFailures($key);
        Owner::login((int)$o['id']);
        Owner::log('owner.login');
        redirect('owner');
    }

    public function logout(): void
    {
        Owner::log('owner.logout');
        Owner::logout();
        flash('info', 'You’ve been signed out of the owner console.');
        redirect('owner/login');
    }

    public function showSetup(): void
    {
        $token = (string)input('token');
        $o = self::setupOwner($token);
        if (!$o) { flash('error', 'This link is invalid or has expired. Run the owner setup again to get a new one.'); redirect('owner/login'); }
        echo view('owner/setup', ['title' => 'Set your owner password', 'token' => $token, 'o' => $o], 'layouts/auth');
    }

    public function setup(): void
    {
        $token = (string)input('token');
        $o = self::setupOwner($token);
        if (!$o) { flash('error', 'This link is invalid or has expired. Run the owner setup again to get a new one.'); redirect('owner/login'); }
        $pw = (string)($_POST['password'] ?? '');
        $name = trim((string)input('name')) ?: 'Platform owner';
        if ($e = self::passwordProblem($pw)) { flash('error', $e); redirect('owner/setup?token=' . urlencode($token)); }
        if ($pw !== (string)($_POST['password_confirm'] ?? '')) { flash('error', 'The two passwords don’t match.'); redirect('owner/setup?token=' . urlencode($token)); }
        DB::update('platform_admins', ['name' => mb_substr($name, 0, 120), 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'setup_token_hash' => null, 'setup_expires_at' => null, 'updated_at' => now_utc()], ['id' => $o['id']]);
        Owner::login((int)$o['id']);
        Owner::log('owner.password_set');
        flash('success', 'Password saved. Welcome to the owner console.');
        redirect('owner');
    }

    // ---------------------------------------------------------------- overview

    public function index(): void
    {
        $stats = [
            'companies'  => (int)DB::value('SELECT COUNT(*) FROM organizations'),
            'suspended'  => (int)DB::value("SELECT COUNT(*) FROM organizations WHERE status = 'suspended'"),
            'new30'      => (int)DB::value('SELECT COUNT(*) FROM organizations WHERE created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY'),
            'users'      => (int)DB::value("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'invited'    => (int)DB::value("SELECT COUNT(*) FROM users WHERE status = 'invited'"),
            'active30'   => (int)DB::value('SELECT COUNT(*) FROM users WHERE last_login_at > UTC_TIMESTAMP() - INTERVAL 30 DAY'),
            'meetings'   => (int)DB::value("SELECT COUNT(*) FROM meetings WHERE status <> 'cancelled'"),
            'meetings30' => (int)DB::value("SELECT COUNT(*) FROM meetings WHERE status <> 'cancelled' AND created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY"),
            'open'       => (int)DB::value("SELECT COUNT(*) FROM actions WHERE status <> 'done'"),
            'overdue'    => (int)DB::value("SELECT COUNT(*) FROM actions WHERE status IN ('open','progress') AND COALESCE(new_deadline, deadline) < UTC_DATE()"),
            'mailFailed' => (int)DB::value("SELECT COUNT(*) FROM outbox WHERE status = 'failed' AND created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY"),
        ];
        $plans = DB::all('SELECT plan, COUNT(*) AS n FROM organizations GROUP BY plan');
        $recent = self::companies('', 'all', 8);
        $log = DB::all('SELECT p.*, o.name AS org_name, u.email AS user_email, a.email AS admin_email
                          FROM platform_audit p
                     LEFT JOIN organizations o ON o.id = p.org_id
                     LEFT JOIN users u ON u.id = p.user_id
                     LEFT JOIN platform_admins a ON a.id = p.admin_id
                      ORDER BY p.id DESC LIMIT 12');
        echo view('owner/index', ['title' => 'Owner overview', 'stats' => $stats, 'plans' => array_column($plans, 'n', 'plan'), 'recent' => $recent, 'log' => $log], 'layouts/owner');
    }

    // ---------------------------------------------------------------- companies

    public function companiesPage(): void
    {
        $q = mb_substr((string)input('q', ''), 0, 100);
        $status = in_array(input('status'), ['active', 'suspended'], true) ? (string)input('status') : 'all';
        echo view('owner/companies', ['title' => 'Companies', 'q' => $q, 'status' => $status, 'companies' => self::companies($q, $status, 500)], 'layouts/owner');
    }

    public function company(int $id): void
    {
        $org = self::org($id);
        $members = DB::all("SELECT u.*, (SELECT COUNT(*) FROM actions a WHERE a.owner_id = u.id AND a.status <> 'done') AS open_items
                              FROM users u WHERE u.org_id = ? ORDER BY u.role = 'admin' DESC, u.status = 'disabled', u.name", [$id]);
        $counts = [
            'meetings'  => (int)DB::value("SELECT COUNT(*) FROM meetings WHERE org_id = ? AND status <> 'cancelled'", [$id]),
            'completed' => (int)DB::value("SELECT COUNT(*) FROM meetings WHERE org_id = ? AND status = 'completed'", [$id]),
            'actions'   => (int)DB::value('SELECT COUNT(*) FROM actions WHERE org_id = ?', [$id]),
            'open'      => (int)DB::value("SELECT COUNT(*) FROM actions WHERE org_id = ? AND status <> 'done'", [$id]),
            'overdue'   => (int)DB::value("SELECT COUNT(*) FROM actions WHERE org_id = ? AND status IN ('open','progress') AND COALESCE(new_deadline, deadline) < UTC_DATE()", [$id]),
        ];
        $integrations = DB::all('SELECT provider, status, account_label FROM integrations WHERE org_id = ?', [$id]);
        $log = DB::all('SELECT p.*, u.email AS user_email FROM platform_audit p LEFT JOIN users u ON u.id = p.user_id WHERE p.org_id = ? ORDER BY p.id DESC LIMIT 10', [$id]);
        $activity = DB::all('SELECT l.event, l.created_at, u.name FROM audit_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.org_id = ? ORDER BY l.id DESC LIMIT 10', [$id]);
        echo view('owner/company', ['title' => $org['name'], 'org' => $org, 'members' => $members, 'counts' => $counts, 'integrations' => $integrations, 'log' => $log, 'activity' => $activity], 'layouts/owner');
    }

    public function plan(int $id): void
    {
        $org = self::org($id);
        $plan = array_key_exists((string)input('plan'), self::PLANS) ? (string)input('plan') : $org['plan'];
        $seatsIn = trim((string)input('seats_limit', ''));
        $seats = $seatsIn === '' ? null : max(1, min(100000, (int)$seatsIn));
        DB::update('organizations', ['plan' => $plan, 'seats_limit' => $seats, 'updated_at' => now_utc()], ['id' => $id]);
        Owner::log('company.plan', $id, null, ['plan' => $plan, 'seats' => $seats, 'was' => $org['plan']]);
        flash('success', 'Plan updated for ' . $org['name'] . '.');
        redirect('owner/companies/' . $id);
    }

    public function companyStatus(int $id): void
    {
        $org = self::org($id);
        $status = input('status') === 'suspended' ? 'suspended' : 'active';
        DB::update('organizations', ['status' => $status, 'updated_at' => now_utc()], ['id' => $id]);
        Owner::log($status === 'suspended' ? 'company.suspended' : 'company.restored', $id);
        flash('success', $status === 'suspended' ? $org['name'] . ' is suspended. Its members can’t sign in.' : $org['name'] . ' is active again.');
        redirect('owner/companies/' . $id);
    }

    // ---------------------------------------------------------------- users

    public function userStatus(int $id): void
    {
        $u = self::member($id);
        $status = input('status') === 'disabled' ? 'disabled' : ($u['password_hash'] ? 'active' : 'invited');
        DB::update('users', ['status' => $status, 'updated_at' => now_utc()], ['id' => $id]);
        Owner::log('user.' . $status, (int)$u['org_id'], $id);
        flash('success', $status === 'disabled' ? $u['name'] . ' can no longer sign in.' : $u['name'] . ' has access again.');
        redirect('owner/companies/' . $u['org_id']);
    }

    public function userRole(int $id): void
    {
        $u = self::member($id);
        $role = input('role') === 'admin' ? 'admin' : 'participant';
        if ($role === 'participant' && $u['role'] === 'admin'
            && (int)DB::value("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'admin' AND status = 'active' AND id <> ?", [$u['org_id'], $id]) === 0) {
            flash('error', 'Each company needs at least one active admin. Make someone else admin first.');
            redirect('owner/companies/' . $u['org_id']);
        }
        DB::update('users', ['role' => $role, 'updated_at' => now_utc()], ['id' => $id]);
        Owner::log('user.role', (int)$u['org_id'], $id, ['role' => $role]);
        flash('success', $u['name'] . ' is now ' . ($role === 'admin' ? 'an admin' : 'a participant') . '.');
        redirect('owner/companies/' . $u['org_id']);
    }

    /** Issue a password reset link (24 h). Emailed to the user, and shown once to the owner to share directly. */
    public function userReset(int $id): void
    {
        $u = self::member($id);
        $token = AuthController::issueToken($id, 'reset', 86400);
        $link = url('reset-password', ['token' => $token]);
        $html = Notifier::layout('Choose a new password', '<p>Hi ' . e($u['name']) . ',</p><p>The ' . e(config('app.name')) . ' team created a link for you to choose a new password. It works for 24 hours.</p>', 'Choose a new password', $link);
        try { Notifier::email(null, $u['email'], $u['name'], 'Choose a new ' . config('app.name') . ' password', $html); } catch (\Throwable $e) { logger('Owner reset email failed: ' . $e->getMessage()); }
        Owner::log('user.reset_link', (int)$u['org_id'], $id);
        $_SESSION['_owner_link'] = ['for' => $u['name'] . ' (' . $u['email'] . ')', 'url' => $link];
        redirect('owner/companies/' . $u['org_id']);
    }

    // ---------------------------------------------------------------- owner account

    public function account(): void
    {
        $log = DB::all('SELECT event, ip, created_at FROM platform_audit WHERE admin_id = ? AND event LIKE ? ORDER BY id DESC LIMIT 8', [Owner::id(), 'owner.%']);
        echo view('owner/account', ['title' => 'Owner account', 'o' => Owner::user(), 'log' => $log], 'layouts/owner');
    }

    public function password(): void
    {
        $o = Owner::user();
        $pw = (string)($_POST['password'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$o['password_hash'])) { flash('error', 'Your current password is not right.'); redirect('owner/account'); }
        if ($e = self::passwordProblem($pw)) { flash('error', $e); redirect('owner/account'); }
        if ($pw !== (string)($_POST['password_confirm'] ?? '')) { flash('error', 'The two new passwords don’t match.'); redirect('owner/account'); }
        DB::update('platform_admins', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'updated_at' => now_utc()], ['id' => $o['id']]);
        Owner::log('owner.password_changed');
        session_regenerate_id(true);
        flash('success', 'Password changed.');
        redirect('owner/account');
    }

    // ---------------------------------------------------------------- helpers

    public static function passwordProblem(string $pw): ?string
    {
        if (mb_strlen($pw) < 10) return 'Use at least 10 characters for the owner password.';
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Use a mix of letters and numbers.';
        return null;
    }

    private static function setupOwner(string $token): ?array
    {
        if ($token === '') return null;
        return DB::one('SELECT * FROM platform_admins WHERE setup_token_hash = ? AND setup_expires_at > UTC_TIMESTAMP()', [Crypto::hash($token)]);
    }

    private static function companies(string $q, string $status, int $limit): array
    {
        $where = ['1=1']; $p = [];
        if ($q !== '') {
            $where[] = '(o.name LIKE :q OR EXISTS (SELECT 1 FROM users su WHERE su.org_id = o.id AND (su.email LIKE :q2 OR su.name LIKE :q3)))';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $p += ['q' => $like, 'q2' => $like, 'q3' => $like];
        }
        if ($status !== 'all') { $where[] = 'o.status = :st'; $p['st'] = $status; }
        return DB::all('SELECT o.*,
                (SELECT COUNT(*) FROM users u WHERE u.org_id = o.id AND u.status <> \'disabled\') AS members,
                (SELECT u.email FROM users u WHERE u.org_id = o.id AND u.role = \'admin\' ORDER BY u.id LIMIT 1) AS admin_email,
                (SELECT COUNT(*) FROM meetings m WHERE m.org_id = o.id AND m.status <> \'cancelled\') AS meetings,
                (SELECT COUNT(*) FROM actions a WHERE a.org_id = o.id AND a.status = \'open\') AS open_items,
                (SELECT MAX(u.last_login_at) FROM users u WHERE u.org_id = o.id) AS last_active
              FROM organizations o WHERE ' . implode(' AND ', $where) . ' ORDER BY o.created_at DESC LIMIT ' . (int)$limit, $p);
    }

    private static function org(int $id): array
    {
        $org = DB::one('SELECT * FROM organizations WHERE id = ?', [$id]);
        if (!$org) abort(404);
        return $org;
    }

    private static function member(int $id): array
    {
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) abort(404);
        return $u;
    }
}
