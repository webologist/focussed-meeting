<?php use App\Core\View; ?>
<div class="row" style="justify-content:space-between">
  <a class="btn ghost sm" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= icon('back', 14) ?><?= $canManage ? 'Edit minutes' : 'Back to meeting' ?></a>
  <div class="row">
    <button type="button" class="btn" data-copy="#mom"><?= icon('doc', 15) ?>Copy as text</button>
    <a class="btn" href="<?= e(path('meetings/' . $m['id'] . '/print/mom')) ?>" target="_blank" rel="noopener"><?= icon('print', 15) ?>Print / PDF</a>
    <?php if ($canManage): ?>
    <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/mom/send')) ?>" class="inline"><?= csrf_field() ?><button class="btn primary" type="submit" data-confirm="Email the minutes to all <?= count($invitees) ?> invitees?"><?= icon('send', 15) ?><?= $m['mom_sent_at'] ? 'Resend' : 'Send' ?> MoM to attendees</button></form>
    <?php endif; ?>
  </div>
</div>
<?= View::partial('meetings/_mom_sheet', get_defined_vars()) ?>
