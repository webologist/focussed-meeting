<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    /** $mw: list of middleware names: 'guest', 'auth', 'verified', 'admin'. */
    public function get(string $pattern, string $handler, array $mw = []): void { $this->add('GET', $pattern, $handler, $mw); }
    public function post(string $pattern, string $handler, array $mw = []): void { $this->add('POST', $pattern, $handler, $mw); }

    private function add(string $method, string $pattern, string $handler, array $mw): void
    {
        $regex = '#^' . preg_replace_callback('#\{(\w+)\}#', fn($m) => '(?P<' . $m[1] . '>' . ($m[1] === 'provider' ? '[a-z]+' : '[0-9]+') . ')', rtrim($pattern, '/') ?: '/') . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'mw');
    }

    public function dispatch(string $method, string $uri): void
    {
        $basePath = rtrim((string)parse_url((string)config('app.url'), PHP_URL_PATH), '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($basePath && str_starts_with($path, $basePath)) $path = substr($path, strlen($basePath)) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        $allowed = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) continue;
            if ($r['method'] !== $method) { $allowed = true; continue; }
            $params = array_map(fn($v) => ctype_digit($v) ? (int)$v : $v, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            if ($method === 'POST') Csrf::verify();
            $this->runMiddleware($r['mw']);
            [$class, $action] = explode('@', $r['handler']);
            $fqcn = 'App\\Controllers\\' . $class;
            (new $fqcn())->$action(...array_values($params));
            return;
        }
        abort($allowed ? 405 : 404);
    }

    private function runMiddleware(array $mw): void
    {
        foreach ($mw as $name) {
            switch ($name) {
                case 'guest':
                    if (Auth::check()) redirect('dashboard');
                    break;
                case 'auth':
                    if (!Auth::check()) {
                        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '';
                        redirect('login');
                    }
                    if (config('app.require_email_verification') && empty(Auth::user()['email_verified_at'])) redirect('verify-email');
                    break;
                case 'auth-unverified':
                    if (!Auth::check()) redirect('login');
                    break;
                case 'admin':
                    if (!is_admin()) abort(403, 'Only admins can do this. Ask an admin in your company for access.');
                    break;
            }
        }
    }
}
