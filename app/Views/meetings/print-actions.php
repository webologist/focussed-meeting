<?php
use App\Services\Actions;
?>
<article class="paper">
  <div class="rule"><div><div class="pk">Action items</div><h1 style="margin-top:4px"><?= e($m['title']) ?></h1></div>
    <div class="meta" style="text-align:right"><?= e($orgName) ?><br><?= e(fmt_date($m['meeting_date'])) ?> · Ref FM-<?= (int)$m['id'] ?></div></div>
  <?php if ($project): ?><p class="meta" style="margin:0">Project: <?= e($project['name']) ?></p><?php endif; ?>
  <?php if (!$actions): ?><p>No action items were recorded for this meeting.</p><?php else: ?>
  <table class="register"><thead><tr><th>#</th><th>Action</th><th>Agenda point</th><th>Responsible</th><th>Priority</th><th>Deadline</th><th>Status</th></tr></thead><tbody>
    <?php $points = array_column($agenda, 'title', 'id'); foreach ($actions as $n => $x): ?>
    <tr><td><?= $n + 1 ?></td><td><?= e($x['title']) ?></td><td><?= e($points[$x['agenda_item_id']] ?? '—') ?></td><td><?= e($x['owner_name']) ?></td><td><?= e(ucfirst($x['priority'])) ?></td>
      <td style="white-space:nowrap"><?= e(fmt_date(Actions::effDeadline($x))) ?></td><td><?= e(Actions::STATUSES[$x['status']]) ?><?= Actions::isOverdue($x) ? ' (overdue)' : '' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <div class="foot">Printed by <?= e($m['organizer_name']) ?> on <?= e(fmt_date(today())) ?>.</div>
</article>
