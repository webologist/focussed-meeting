<div class="head"><div><div class="eyebrow">Alerts</div><h1>Notifications</h1><p class="muted">Reminders, assignments, comments and updates on your work.</p></div></div>
<div class="panel"><div class="list">
  <?php if (!$items): ?><div class="empty">Nothing here yet.</div><?php endif; ?>
  <?php foreach ($items as $n): ?>
  <div class="li"><span style="color:<?= $n['read_at'] ? 'var(--ink-3)' : 'var(--accent)' ?>"><?= icon($n['read_at'] ? 'bell' : 'bell', 16) ?></span>
    <div style="min-width:0"><div class="<?= $n['read_at'] ? '' : 'title' ?>"><?php if ($n['link']): ?><a class="linkish" href="<?= e(path($n['link'])) ?>"><?= e($n['body']) ?></a><?php else: ?><?= e($n['body']) ?><?php endif; ?></div>
      <div class="meta"><span><?= e(fmt_local($n['created_at'])) ?></span><?php if (!$n['read_at']): ?><span class="pill s-sched">New</span><?php endif; ?></div></div><span></span></div>
  <?php endforeach; ?>
</div></div>
