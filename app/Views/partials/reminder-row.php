<?php
$local = new DateTime($r['remind_at'], new DateTimeZone('UTC'));
$local->setTimezone(new DateTimeZone(user_tz()));
$d = days_between($local->format('Y-m-d'), today());
$day = $d === 0 ? 'Today' : ($d === 1 ? 'Tomorrow' : ($d === -1 ? 'Yesterday' : $local->format('j M')));
$via = array_filter(['In-app', $r['via_email'] ? 'Email' : null, $r['via_whatsapp'] ? 'WhatsApp' : null]);
$past = !empty($r['fired_at']);
?>
<div class="rem <?= $past ? 'past' : ($d <= 1 ? 'soon' : '') ?>">
  <div class="when"><?= e($day) ?><b><?= e($local->format('g:i A')) ?></b></div>
  <div style="min-width:0"><div class="title"><?= e($r['body']) ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:.76rem;color:var(--ink-3);margin-top:2px">
      <?php if (!empty($r['action_id'])): ?><a class="linkish" href="<?= e(path('actions/' . $r['action_id'])) ?>"><?= icon('link', 12) ?> <?= e($r['action_title'] ?? 'Linked item') ?></a><?php endif; ?>
      <span><?= e(implode(' · ', $via)) ?></span></div></div>
  <form method="post" action="<?= e(path('reminders/' . $r['id'] . '/delete')) ?>" class="inline"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Delete reminder"><?= icon('x', 14) ?></button></form>
</div>
