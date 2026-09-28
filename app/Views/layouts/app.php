<?php
use App\Core\DB;
use App\Core\Csrf;
use App\Services\Access;

$u = user();
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim((string)parse_url((string)config('app.url'), PHP_URL_PATH), '/');
if ($basePath && str_starts_with($reqPath, $basePath)) $reqPath = substr($reqPath, strlen($basePath));
$section = explode('/', trim($reqPath, '/'))[0] ?: 'dashboard';
if ($section === 'actions') $section = $_GET['from'] ?? 'tracker';

$mineOpen = (int)DB::value("SELECT COUNT(*) FROM actions WHERE org_id = ? AND owner_id = ? AND status = 'open'", [org_id(), uid()]);
$mineLate = (int)DB::value("SELECT COUNT(*) FROM actions WHERE org_id = ? AND owner_id = ? AND status = 'open' AND COALESCE(new_deadline, deadline) < ?", [org_id(), uid(), today()]);
[$scope, $sp] = Access::actionScope('a');
$trackerOpen = (int)DB::value("SELECT COUNT(*) FROM actions a WHERE a.status = 'open' AND $scope", $sp);
$unread = (int)DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [uid()]);

$nav = [
    ['dashboard', 'My dashboard', 'home', $mineOpen, $mineLate > 0],
    ['tracker', 'Action tracker', 'dash', $trackerOpen, false],
    ['meetings', 'Meetings', 'cal', null, false],
];
if (is_admin()) $nav[] = ['reports', 'Reports', 'chart', null, false];
$nav2 = [];
if (is_admin()) $nav2[] = ['integrations', 'Integrations', 'plug', null, false];
$nav2[] = ['team', 'Team', 'users', null, false];
$nav2[] = ['account', 'My account', 'user', null, false];
$navBtn = function (array $n) use ($section) {
    [$key, $label, $ic, $count, $alert] = $n;
    $badge = $count !== null ? '<span class="count' . ($alert ? ' alert' : '') . '">' . (int)$count . '</span>' : '';
    return '<a href="' . e(path($key)) . '" class="navlink' . ($section === $key ? ' on' : '') . '">' . icon($ic) . e($label) . $badge . '</a>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title><?= e(($title ?? '') ? $title . ' · ' : '') ?><?= e(config('app.name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand">
      <div class="brand-mark" style="color:var(--accent-ink)"><?= icon('logo', 18) ?></div>
      <div class="brand-name"><?= e(config('app.name')) ?><small><?= e($u['org_name']) ?></small></div>
    </div>
    <nav class="nav" aria-label="Main">
      <?php foreach ($nav as $n) echo $navBtn($n); ?>
      <div class="nav-sep"></div>
      <?php foreach ($nav2 as $n) echo $navBtn($n); ?>
    </nav>
    <div class="side-foot">
      <a class="me" href="<?= e(path('account')) ?>" style="color:inherit;text-decoration:none"><?= avatar($u, 32) ?>
        <div style="min-width:0"><div style="font-weight:600"><?= e($u['name']) ?> <span class="pill <?= $u['role'] === 'admin' ? 'p-admin' : 's-neutral' ?>" style="font-size:.64rem;padding:0 6px;vertical-align:1px"><?= $u['role'] === 'admin' ? 'Admin' : 'Participant' ?></span></div>
        <div class="faint" style="font-size:.74rem;overflow:hidden;text-overflow:ellipsis"><?= e($u['email']) ?></div></div></a>
      <div class="row" style="gap:6px">
        <a class="btn ghost sm bell" href="<?= e(path('notifications')) ?>" title="Notifications"><?= icon('bell', 15) ?>Alerts<?php if ($unread): ?><span class="n"><?= $unread ?></span><?php endif; ?></a>
        <form method="post" action="<?= e(path('logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn ghost sm" type="submit"><?= icon('logout', 15) ?>Sign out</button></form>
      </div>
    </div>
  </aside>
  <header class="topbar">
    <div class="row">
      <div class="brand" style="padding:0"><div class="brand-mark" style="color:var(--accent-ink)"><?= icon('logo', 18) ?></div><div class="brand-name"><?= e(config('app.name')) ?></div></div>
      <div class="top-actions">
        <a class="icon-btn bell" href="<?= e(path('notifications')) ?>" aria-label="Notifications"><?= icon('bell', 18) ?><?php if ($unread): ?><span class="n"><?= $unread ?></span><?php endif; ?></a>
        <form method="post" action="<?= e(path('logout')) ?>" class="inline"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Sign out"><?= icon('logout', 18) ?></button></form>
      </div>
    </div>
    <nav class="topnav" aria-label="Main">
      <?php foreach (array_merge($nav, $nav2) as [$key, $label]): ?>
        <a href="<?= e(path($key)) ?>" class="<?= $section === $key ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>
  <main class="main"><div class="wrap">
    <?php $fl = take_flashes(); if ($fl): ?><div class="flashes" role="status"><?php foreach ($fl as $f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?= $content ?>
  </div></main>
</div>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
