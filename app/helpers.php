<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;

function config(string $key, mixed $default = null): mixed { return Config::get($key, $default); }

/** HTML-escape for output. Use for every piece of user data printed into a page. */
function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function url(string $path = '', array $query = []): string
{
    $base = rtrim((string)config('app.url'), '/');
    $u = $base . '/' . ltrim($path, '/');
    return $query ? $u . '?' . http_build_query($query) : $u;
}

/** Relative app path (works whether the app runs at domain root or in a sub-folder). */
function path(string $p = '', array $query = []): string
{
    $basePath = rtrim((string)parse_url((string)config('app.url'), PHP_URL_PATH), '/');
    $u = $basePath . '/' . ltrim($p, '/');
    return $query ? $u . '?' . http_build_query($query) : $u;
}

function asset(string $p): string
{
    $file = BASE_PATH . '/public/assets/' . $p;
    $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : '1';
    return path('assets/' . $p) . '?v=' . $v;
}

function redirect(string $to, int $code = 302): never
{
    if (!preg_match('~^https?://~', $to)) $to = path($to);
    header('Location: ' . $to, true, $code);
    exit;
}

function back(string $fallback = 'dashboard'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = parse_url((string)config('app.url'), PHP_URL_HOST);
    if ($ref && parse_url($ref, PHP_URL_HOST) === $host) { header('Location: ' . $ref, true, 302); exit; }
    redirect($fallback);
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $titles = [403 => 'Not allowed', 404 => 'Page not found', 419 => 'Page expired', 429 => 'Too many attempts', 500 => 'Something went wrong'];
    echo View::render('errors/error', ['code' => $code, 'title' => $titles[$code] ?? 'Error', 'message' => $message], 'layouts/bare');
    exit;
}

function view(string $template, array $data = [], string $layout = 'layouts/app'): string { return View::render($template, $data, $layout); }

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

function csrf_field(): string { return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">'; }

function flash(string $type, string $message): void { $_SESSION['_flash'][] = ['type' => $type, 'message' => $message]; }

function take_flashes(): array { $f = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $f; }

function old(string $key, string $default = ''): string { return (string)($_SESSION['_old'][$key] ?? $default); }

function keep_old(array $data): void { $_SESSION['_old'] = array_map(fn($v) => is_string($v) ? $v : '', $data); }

function clear_old(): void { unset($_SESSION['_old']); }

function user(): ?array { return Auth::user(); }

function uid(): int { return (int)(Auth::user()['id'] ?? 0); }

function org_id(): int { return (int)(Auth::user()['org_id'] ?? 0); }

function is_admin(): bool { return (Auth::user()['role'] ?? '') === 'admin'; }

function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

/** Timezone used to display dates for the signed-in user. */
function user_tz(): string
{
    $u = Auth::user();
    return $u['timezone'] ?? $u['org_timezone'] ?? (string)config('app.timezone', 'UTC');
}

/** Today's date (Y-m-d) in the user's timezone. */
function today(): string { return (new DateTime('now', new DateTimeZone(user_tz())))->format('Y-m-d'); }

function date_add_days(string $ymd, int $days): string { return (new DateTime($ymd))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d'); }

function days_between(string $a, string $b): int { return (int)round((strtotime($a . ' 00:00:00 UTC') - strtotime($b . ' 00:00:00 UTC')) / 86400); }

function fmt_date(?string $ymd, bool $withYear = true): string
{
    if (!$ymd) return '—';
    $t = strtotime(substr($ymd, 0, 10) . ' 00:00:00 UTC');
    return gmdate($withYear ? 'j M Y' : 'j M', $t);
}

function fmt_time(?string $hm): string
{
    if (!$hm) return '';
    [$h, $m] = array_map('intval', explode(':', $hm));
    return ($h % 12 ?: 12) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' ' . ($h >= 12 ? 'PM' : 'AM');
}

/** Convert a UTC datetime string into the user's timezone, formatted. */
function fmt_local(?string $utc, string $format = 'j M Y, g:i A'): string
{
    if (!$utc) return '—';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(user_tz()));
    return $d->format($format);
}

/** Convert a local date+time (in the user's tz) to a UTC datetime string. */
function local_to_utc(string $ymd, string $hm, ?string $tz = null): string
{
    $d = new DateTime("$ymd $hm:00", new DateTimeZone($tz ?? user_tz()));
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s');
}

function plural(int $n, string $word, ?string $pluralWord = null): string { return $n . ' ' . ($n === 1 ? $word : ($pluralWord ?? $word . 's')); }

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) $s .= mb_substr($p, 0, 1);
    return mb_strtoupper($s ?: '?');
}

function color_for(int|string $id): string
{
    $colors = ['#1F6F78', '#7A4FB5', '#B5543A', '#2F6BB0', '#3F7D4E', '#A0527A', '#8A6D1F'];
    return $colors[crc32((string)$id) % count($colors)];
}

function avatar(array $person, int $size = 26): string
{
    $fs = max(0.6, $size / 40);
    return '<span class="avatar" style="background:' . color_for($person['id'] ?? 0) . ";width:{$size}px;height:{$size}px;font-size:{$fs}rem\" aria-hidden=\"true\">" . e(initials($person['name'] ?? '?')) . '</span>';
}

function person_chip(array $person, int $size = 22): string
{
    $name = ($person['name'] ?? 'Unknown') . ((int)($person['id'] ?? 0) === uid() ? ' (you)' : '');
    return '<span class="person">' . avatar($person, $size) . '<span>' . e($name) . '</span></span>';
}

function pri_pill(string $p): string { return '<span class="pill p-' . e($p) . '"><span class="dot"></span>' . e(ucfirst($p)) . '</span>'; }

function icon(string $name, int $size = 16): string { return App\Core\Icons::svg($name, $size); }

function platform_label(string $p): string { return ['meet' => 'Google Meet', 'teams' => 'Microsoft Teams', 'inperson' => 'In person'][$p] ?? $p; }

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function logger(string $message, array $context = []): void
{
    $line = '[' . gmdate('c') . '] ' . $message . ($context ? ' ' . json_encode($context) : '') . PHP_EOL;
    @file_put_contents(STORAGE_PATH . '/logs/app.log', $line, FILE_APPEND);
}

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }
