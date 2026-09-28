<?php
// One-time database setup: php database/install.php
// Creates all tables in the database named in config/config.php.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;

$exists = DB::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'organizations'");
if ($exists) { echo "Tables already exist. Nothing to do.\n"; exit(0); }
$sql = file_get_contents(__DIR__ . '/schema.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) DB::pdo()->exec($stmt);
echo "Database ready. Open " . config('app.url') . "/register to create the first company.\n";
