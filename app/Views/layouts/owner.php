<?php
use App\Core\Csrf;
use App\Core\Owner;

$o = Owner::user();
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim((string)parse_url((string)config('app.url'), PHP_URL_PATH), '/');
if ($basePath && str_starts_with($reqPath, $basePath)) $reqPath = substr($reqPath, strlen($basePath));
$parts = explode('/', trim($reqPath, '/'));
$section = $parts[1] ?? '';
$nav = [
    ['owner', '', 'Overview', 'dash'],
    ['owner/companies', 'companies', 'Companies', 'building'],
    ['owner/account', 'account', 'Owner account', 'user'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title ?? '') ? $title . ' · ' : '') ?>Owner · <?= e(config('app.name')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="owner-console">
<div class="app">
  <aside class="side">
    <div class="brand">
      <div class="brand-mark" style="color:var(--accent-ink)"><?= icon('shield', 18) ?></div>
      <div class="brand-name"><?= e(config('app.name')) ?><small>Platform owner</small></div>
    </div>
    <nav class="nav" aria-label="Owner">
      <?php foreach ($nav as [$href, $key, $label, $ic]): ?>
        <a href="<?= e(path($href)) ?>" class="navlink<?= $section === $key ? ' on' : '' ?>"><?= icon($ic) ?><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="me"><span class="avatar" style="width:32px;height:32px;background:#1d2a33;font-size:.8rem"><?= e(initials($o['name'])) ?></span>
        <div style="min-width:0"><div style="font-weight:600"><?= e($o['name']) ?> <span class="pill p-admin" style="font-size:.64rem;padding:0 6px;vertical-align:1px">Owner</span></div>
        <div class="faint" style="font-size:.74rem;overflow:hidden;text-overflow:ellipsis"><?= e($o['email']) ?></div></div></div>
      <form method="post" action="<?= e(path('owner/logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn ghost sm" type="submit" data-confirm="Sign out of <?= e(config('app.name')) ?>?"><?= icon('logout', 15) ?>Sign out</button></form>
    </div>
  </aside>
  <header class="topbar">
    <div class="row">
      <div class="brand" style="padding:0"><div class="brand-mark" style="color:var(--accent-ink)"><?= icon('shield', 18) ?></div><div class="brand-name">Owner console</div></div>
      <div class="top-actions">
        <form method="post" action="<?= e(path('owner/logout')) ?>" class="inline"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Sign out" data-confirm="Sign out of <?= e(config('app.name')) ?>?"><?= icon('logout', 18) ?></button></form>
      </div>
    </div>
    <nav class="topnav" aria-label="Owner">
      <?php foreach ($nav as [$href, $key, $label]): ?><a href="<?= e(path($href)) ?>" class="<?= $section === $key ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
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
