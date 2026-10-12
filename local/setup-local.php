<?php
// First-time local setup for Windows + LocalWP (run by start-local.bat).
// Finds LocalWP's running MySQL, creates the focused_meetings database, imports the schema
// and writes config/config.php. Safe to run again: it never overwrites an existing config.
declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
if (is_file($configFile)) { echo "Config already exists. Skipping setup.\n"; exit(0); }

foreach (['pdo_mysql', 'openssl', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) { fwrite(STDERR, "PHP extension '$ext' is not loaded. Tell Claude this message.\n"); exit(1); }
}

// 1. Find MySQL: LocalWP stores each site's MySQL port in %APPDATA%\Local\sites.json
$candidates = [];
$sitesJson = getenv('APPDATA') . '\\Local\\sites.json';
if (is_file($sitesJson)) {
    $sites = json_decode((string)file_get_contents($sitesJson), true) ?: [];
    foreach ($sites as $s) {
        foreach (($s['services']['mysql']['ports'] ?? []) as $ports) foreach ((array)$ports as $p) $candidates[] = [(int)$p, $s['name'] ?? '?'];
    }
}
$candidates[] = [3306, 'default'];
$candidates[] = [3307, 'default'];

$pdo = null; $port = 0;
// The app's own MySQL started by start-local.bat (root, no password). Give it up to 60 s to come up.
$own = (int)getenv('FM_DB_PORT');
if ($own) {
    echo "Waiting for the database on port $own";
    for ($i = 0; $i < 30 && !$pdo; $i++) {
        try {
            $pdo = new PDO("mysql:host=127.0.0.1;port=$own;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
            $port = $own; $user = 'root'; $pass = '';
        } catch (Throwable $e) { echo '.'; sleep(2); }
    }
    echo $pdo ? " connected.\n" : " not reachable, trying other MySQL servers.\n";
}
if (!$pdo) foreach ($candidates as [$p, $name]) {
    foreach ([['root', 'root'], ['root', '']] as [$u, $pw]) {
        try {
            $pdo = new PDO("mysql:host=127.0.0.1;port=$p;charset=utf8mb4", $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
            $port = $p; $user = $u; $pass = $pw;
            echo "Connected to MySQL on port $p (LocalWP site: $name).\n";
            break 2;
        } catch (Throwable $e) { $pdo = null; }
    }
}
if (!$pdo) {
    fwrite(STDERR, "\nCould not reach MySQL.\nClose all MeetFlow windows and run start-local.bat again. If it still fails, send Claude a screenshot of the minimised 'MeetFlow database' window.\n");
    exit(1);
}

// 2. Create the database and tables
$pdo->exec("CREATE DATABASE IF NOT EXISTS focused_meetings CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE focused_meetings");
$has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'focused_meetings' AND table_name = 'organizations'")->fetchColumn();
if (!$has) {
    $sql = preg_replace('/^--.*$/m', '', (string)file_get_contents($root . '/database/schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);
    echo "Database focused_meetings created.\n";
} else {
    echo "Database focused_meetings already has tables. Keeping them.\n";
}

// 3. Write config/config.php from the sample
$cfg = (string)file_get_contents($root . '/config/config.sample.php');
$replace = [
    "'url'       => 'http://localhost:8000'" => "'url'       => 'http://localhost:8080'",
    "'key'       => 'CHANGE_ME_64_HEX_CHARS'" => "'key'       => '" . bin2hex(random_bytes(32)) . "'",
    "'env'       => 'production'" => "'env'       => 'development'",
    "'require_email_verification' => true" => "'require_email_verification' => false",
    "'port'     => 3306" => "'port'     => $port",
    "'username' => 'root',\n        'password' => ''," => "'username' => '" . $user . "',\n        'password' => '" . $pass . "',",
    "'host'       => 'smtp.hostinger.com'" => "'host'       => ''",
    "'fallback_for_companies' => true" => "'fallback_for_companies' => false",
];
$cfg = str_replace(array_keys($replace), array_values($replace), $cfg);
file_put_contents($configFile, $cfg);
foreach (['storage/logs', 'storage/cache', 'storage/uploads'] as $d) if (!is_dir("$root/$d")) mkdir("$root/$d", 0777, true);
echo "Config written. Setup complete.\n";
