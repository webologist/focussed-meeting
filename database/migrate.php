<?php
// Brings an existing database up to date. Safe to run any number of times:
//   php database/migrate.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;

function table_exists(string $t): bool
{
    return (bool)DB::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
}
function column_exists(string $t, string $c): bool
{
    return (bool)DB::value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
}
function column_type(string $t, string $c): string
{
    return (string)DB::value('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
}
function index_exists(string $t, string $name): bool
{
    return (bool)DB::value('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $name]);
}
function fk_exists(string $t, string $name): bool
{
    return (bool)DB::value('SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ?', [$t, $name]);
}

// The local database may still be starting: wait up to 60 seconds for it.
for ($i = 0; ; $i++) {
    try { DB::pdo(); break; }
    catch (Throwable $e) { if ($i >= 30) { fwrite(STDERR, 'Could not reach the database: ' . $e->getMessage() . "\n"); exit(1); } sleep(2); }
}

if (!table_exists('organizations')) { echo "No tables yet. Run database/install.php first.\n"; exit(1); }

$changes = 0;
$run = function (string $sql) use (&$changes): void { DB::pdo()->exec($sql); $changes++; };

// 1. Create any table from schema.sql that does not exist yet (projects, oauth_clients, platform owner tables, ...)
$sql = preg_replace('/^--.*$/m', '', (string)file_get_contents(__DIR__ . '/schema.sql'));
DB::pdo()->exec('SET foreign_key_checks = 0');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if (preg_match('/^CREATE TABLE\s+`?(\w+)`?/i', $stmt, $m) && !table_exists($m[1])) $run($stmt);
}
DB::pdo()->exec('SET foreign_key_checks = 1');

// 2. Columns added to existing tables
$columns = [
    ['organizations', 'status', "ENUM('active','suspended') NOT NULL DEFAULT 'active' AFTER seats_limit"],
    ['meetings', 'project_id', 'INT UNSIGNED NULL AFTER org_id'],
    ['meetings', 'sent_personal', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER sent_whatsapp'],
    ['meetings', 'personal_note', 'TEXT NULL AFTER sent_personal'],
    ['agenda_items', 'request_status', "ENUM('pending','approved','declined') NULL AFTER added_in_meeting"],
    ['agenda_items', 'requested_by', 'INT UNSIGNED NULL AFTER request_status'],
    ['agenda_items', 'review_note', 'VARCHAR(500) NULL AFTER requested_by'],
    ['agenda_items', 'reviewed_by', 'INT UNSIGNED NULL AFTER review_note'],
    ['agenda_items', 'reviewed_at', 'DATETIME NULL AFTER reviewed_by'],
    ['actions', 'project_id', 'INT UNSIGNED NULL AFTER org_id'],
    ['actions', 'submitted_at', 'DATETIME NULL AFTER status'],
    ['actions', 'approved_by', 'INT UNSIGNED NULL AFTER submitted_at'],
];
foreach ($columns as [$t, $c, $def]) {
    if (!column_exists($t, $c)) $run("ALTER TABLE `$t` ADD COLUMN `$c` $def");
}

// 3. Task statuses: To do, In progress, Awaiting approval, Done
if (!str_contains(column_type('actions', 'status'), 'review')) {
    $run("ALTER TABLE actions MODIFY status ENUM('open','progress','review','done') NOT NULL DEFAULT 'open'");
}

// 4. Indexes and foreign keys for projects
if (!index_exists('meetings', 'idx_meetings_project')) $run('ALTER TABLE meetings ADD KEY idx_meetings_project (project_id)');
if (!index_exists('actions', 'idx_actions_project')) $run('ALTER TABLE actions ADD KEY idx_actions_project (project_id)');
if (!fk_exists('meetings', 'fk_meetings_project')) $run('ALTER TABLE meetings ADD CONSTRAINT fk_meetings_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
if (!fk_exists('actions', 'fk_actions_project')) $run('ALTER TABLE actions ADD CONSTRAINT fk_actions_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');

echo $changes ? "Database updated ($changes change" . ($changes === 1 ? '' : 's') . ").\n" : "Database is up to date.\n";
