<?php use App\Core\View; ?>
<div class="row" style="justify-content:space-between">
  <a class="btn ghost sm" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= icon('back', 14) ?><?= $canManage ? 'Edit minutes' : 'Back to meeting' ?></a>
  <div class="row">
    <button type="button" class="btn" data-copy="#mom"><?= icon('doc', 15) ?>Copy as text</button>
    <?php if ($isHost): ?>
    <details class="menu"><summary class="btn"><?= icon('print', 15) ?>Print</summary>
      <div class="menu-pop menu-list">
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/mom')) ?>" target="_blank" rel="noopener">Minutes of meeting</a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/attendance')) ?>" target="_blank" rel="noopener">Attendance register</a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/actions')) ?>" target="_blank" rel="noopener">Action items</a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/invite')) ?>" target="_blank" rel="noopener">Meeting invitation</a>
      </div></details>
    <?php endif; ?>
    <?php if ($canManage): ?>
    <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/mom/send')) ?>" class="inline"><?= csrf_field() ?><button class="btn primary" type="submit" data-confirm="Email the minutes to all <?= count($invitees) ?> invitees?"><?= icon('send', 15) ?><?= $m['mom_sent_at'] ? 'Resend' : 'Send' ?> MoM to attendees</button></form>
    <?php endif; ?>
  </div>
</div>
<?= View::partial('meetings/_mom_sheet', get_defined_vars()) ?>
