<?php
use App\Services\Meetings;
$others = array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']);
?>
<article class="paper">
  <div class="rule"><div><div class="pk">Meeting invitation</div><h1 style="margin-top:4px"><?= e($m['title']) ?></h1></div>
    <div class="meta" style="text-align:right"><?= e(user()['org_name']) ?><br>Ref FM-<?= (int)$m['id'] ?></div></div>
  <table><tbody>
    <tr><th>Date</th><td><?= e(gmdate('l', strtotime($m['meeting_date'] . ' 00:00:00 UTC'))) ?>, <?= e(fmt_date($m['meeting_date'])) ?></td></tr>
    <tr><th>Time</th><td><?= e(fmt_time($m['start_time'])) ?> (<?= (int)$m['duration_min'] ?> min) · <?= e($m['timezone']) ?></td></tr>
    <tr><th>Where</th><td style="overflow-wrap:anywhere"><?= e($m['platform'] === 'inperson' ? ($m['location'] ?: 'To be confirmed') : platform_label($m['platform'])) ?><?php if ($m['platform'] !== 'inperson' && $m['join_url']): ?><br><?= e($m['join_url']) ?><?php endif; ?></td></tr>
    <tr><th>Organiser</th><td><?= e($m['organizer_name']) ?> · <?= e($m['organizer_email']) ?></td></tr>
    <tr><th>Invitees</th><td><?= e(implode(', ', array_column($others, 'name'))) ?></td></tr>
  </tbody></table>
  <div><div class="pk" style="margin-bottom:8px">Agenda</div>
    <ol>
      <?php foreach ($agenda as $p): ?>
      <li><b><?= e($p['title']) ?></b><?php $meta = array_filter([$p['presenter_name'], $p['minutes_allotted'] ? $p['minutes_allotted'] . ' min' : null]); if ($meta): ?> <span class="meta">· <?= e(implode(' · ', $meta)) ?></span><?php endif; ?>
        <?php if ($p['details']): ?><div class="d"><?= e($p['details']) ?></div><?php endif; ?>
        <?php if ($p['subpoints']): ?><ul><?php foreach ($p['subpoints'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul><?php endif; ?></li>
      <?php endforeach; ?>
    </ol></div>
  <div class="foot">Please review the agenda and come prepared. Minutes and action items will be shared after the meeting.</div>
</article>
