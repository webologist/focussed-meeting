<?php
// LOCAL DEVELOPMENT ONLY. Sets the same password for every user (and the platform owner) so you can sign in as anyone while testing.
//   php local/reset-passwords.php "NewPassword"
// Refuses to run unless app.env is 'development' and app.url is a localhost address.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;

$host = (string)parse_url((string)config('app.url'), PHP_URL_HOST);
if (config('app.env') !== 'development' || !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
    fwrite(STDERR, "Refused: this only runs on a local development copy (app.env = development, app.url on localhost).\n");
    exit(1);
}
$pw = (string)($argv[1] ?? '');
if (strlen($pw) < 8) { fwrite(STDERR, "Usage: php local/reset-passwords.php \"NewPassword\" (at least 8 characters)\n"); exit(1); }

$hash = password_hash($pw, PASSWORD_DEFAULT);
$users = DB::run("UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE status <> 'invited'", [$hash])->rowCount();
$owners = DB::run('UPDATE platform_admins SET password_hash = ?, setup_token_hash = NULL, setup_expires_at = NULL, updated_at = UTC_TIMESTAMP()', [$hash])->rowCount();
DB::run('DELETE FROM login_attempts');
DB::run("UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE type = 'reset' AND used_at IS NULL");

echo "Password reset for $users user" . ($users === 1 ? '' : 's') . " and $owners platform owner" . ($owners === 1 ? '' : 's') . ".\n";
echo "People who were invited but have not registered yet were left as they are.\n\n";
foreach (DB::all("SELECT o.name AS org, u.email, u.role, u.status FROM users u JOIN organizations o ON o.id = u.org_id WHERE u.status <> 'invited' ORDER BY o.name, u.role, u.email") as $r) {
    echo str_pad($r['org'], 30) . str_pad($r['email'], 34) . $r['role'] . ($r['status'] === 'disabled' ? ' (access off)' : '') . "\n";
}
foreach (DB::all('SELECT email FROM platform_admins') as $r) echo str_pad('Platform owner (/owner/login)', 30) . $r['email'] . "\n";
