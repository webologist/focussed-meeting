<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) return;
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

require APP_PATH . '/helpers.php';

$configFile = BASE_PATH . '/config/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Missing config/config.php. Copy config/config.sample.php first.\n"); exit(1); }
    http_response_code(500);
    echo '<h1>Setup needed</h1><p>Copy <code>config/config.sample.php</code> to <code>config/config.php</code> and fill in your database details. See README.md.</p>';
    exit;
}
App\Core\Config::load(require $configFile);

date_default_timezone_set('UTC'); // all timestamps stored in UTC; displayed in the company/user timezone

if (config('app.env') === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
