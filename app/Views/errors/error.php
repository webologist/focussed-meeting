<div class="center-card">
  <div class="panel" style="width:min(460px,100%)"><div class="panel-b stack">
    <div class="eyebrow">Error <?= (int)$code ?></div>
    <h1><?= e($title) ?></h1>
    <?php if ($message): ?><p class="muted" style="margin:0"><?= e($message) ?></p><?php endif; ?>
    <div class="row"><a class="btn primary" href="<?= e(path(user() ? 'dashboard' : '')) ?>">Go to <?= user() ? 'dashboard' : 'home page' ?></a><a class="btn" href="javascript:history.back()">Go back</a></div>
  </div></div>
</div>
