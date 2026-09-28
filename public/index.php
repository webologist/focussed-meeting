<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Router;
use App\Core\Session;

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
if (str_starts_with((string)config('app.url'), 'https://')) header('Strict-Transport-Security: max-age=31536000');

Session::start();

$router = new Router();
require APP_PATH . '/routes.php';

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $e) {
    logger('Unhandled: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    if (config('app.env') === 'development') {
        http_response_code(500);
        echo '<pre>' . e((string)$e) . '</pre>';
        exit;
    }
    abort(500, 'Please try again. If it keeps happening, contact support.');
}
