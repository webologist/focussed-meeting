<?php
// Create the platform owner (super admin), or issue a new password link for an existing one.
//   php database/owner.php owner@example.com
// Prints a one-time link (valid 1 hour) where the owner sets their password. Add --open to open it in the browser.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Crypto;
use App\Core\DB;

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--open'));
$open = in_array('--open', $argv, true);
$email = mb_strtolower(trim((string)($args[0] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Usage: php database/owner.php owner@example.com [--open]\n"); exit(1); }

$token = Crypto::token();
$row = DB::one('SELECT * FROM platform_admins WHERE email = ?', [$email]);
$data = ['setup_token_hash' => Crypto::hash($token), 'setup_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)];
if ($row) {
    DB::update('platform_admins', $data + ['updated_at' => now_utc()], ['id' => $row['id']]);
    $id = (int)$row['id'];
    echo "Platform owner $email already exists. Issued a new password link.\n";
} else {
    $id = DB::insert('platform_admins', $data + ['email' => $email, 'name' => 'Platform owner', 'created_at' => now_utc()]);
    echo "Platform owner $email created.\n";
}
DB::insert('platform_audit', ['admin_id' => $id, 'event' => 'owner.setup_link', 'ip' => 'cli', 'created_at' => now_utc()]);

$link = url('owner/setup', ['token' => $token]);
echo "\nOpen this link within 1 hour to set the password:\n$link\n\nAfter that, sign in at " . url('owner/login') . "\n";

if ($open) {
    if (PHP_OS_FAMILY === 'Windows') pclose(popen('start "" "' . $link . '"', 'r'));
    elseif (PHP_OS_FAMILY === 'Darwin') exec('open ' . escapeshellarg($link));
    else exec('xdg-open ' . escapeshellarg($link) . ' >/dev/null 2>&1 &');
}
