<?php ob_start(); ?>
<div class="auth-wrap">
  <aside class="auth-side">
    <a class="brand-inline" href="<?= e(path('')) ?>" style="color:inherit;text-decoration:none"><span class="brand-mark"><?= icon('logo', 18) ?></span><?= e(config('app.name')) ?></a>
    <div class="stack" style="gap:18px">
      <h2>Meetings that end with owners, deadlines and follow-through.</h2>
      <ul>
        <li><?= icon('check') ?>Send a point-by-point agenda by email, Google Meet or Teams</li>
        <li><?= icon('check') ?>Capture minutes and assign action items right after the meeting</li>
        <li><?= icon('check') ?>Track deadlines, dependencies and reminders on one dashboard</li>
        <li><?= icon('check') ?>Share minutes of meeting with one click</li>
      </ul>
    </div>
    <span style="font-size:.8rem;opacity:.8">&copy; <?= date('Y') ?> <?= e(config('app.name')) ?></span>
  </aside>
  <main class="auth-main">
    <div class="auth-card">
      <?php foreach (take_flashes() as $f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
      <?= $content ?>
    </div>
  </main>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/bare.php'; ?>
