<?php
$roster = array_merge(array_filter($invitees, fn($i) => (int)$i['id'] === (int)$m['organizer_id']), array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
$recorded = $m['status'] === 'completed';
?>
<article class="paper">
  <div class="rule"><div><div class="pk">Attendance register</div><h1 style="margin-top:4px"><?= e($m['title']) ?></h1></div>
    <div class="meta" style="text-align:right"><?= e(user()['org_name']) ?><br>Ref FM-<?= (int)$m['id'] ?></div></div>
  <table><tbody>
    <?php if ($project): ?><tr><th>Project</th><td><?= e($project['name']) ?></td></tr><?php endif; ?>
    <tr><th>Date &amp; time</th><td><?= e(gmdate('l', strtotime($m['meeting_date'] . ' 00:00:00 UTC'))) ?>, <?= e(fmt_date($m['meeting_date'])) ?>, <?= e(fmt_time($m['start_time'])) ?></td></tr>
    <tr><th>Venue</th><td><?= e($m['platform'] === 'inperson' ? ($m['location'] ?: '—') : platform_label($m['platform'])) ?></td></tr>
    <tr><th>Chaired by</th><td><?= e($m['organizer_name']) ?></td></tr>
  </tbody></table>
  <table class="register"><thead><tr><th>#</th><th>Name</th><th>Designation</th><th>Role</th><?php if ($recorded): ?><th>Recorded</th><?php endif; ?><th>Signature</th></tr></thead><tbody>
    <?php foreach (array_values($roster) as $n => $i): ?>
    <tr><td><?= $n + 1 ?></td><td><?= e($i['name']) ?></td><td><?= e($i['title'] ?: '') ?></td><td><?= (int)$i['id'] === (int)$m['organizer_id'] ? 'Chair' : 'Member' ?></td>
      <?php if ($recorded): ?><td><?= (int)$i['attended'] === 1 ? 'Present' : 'Absent' ?></td><?php endif; ?><td class="sig"></td></tr>
    <?php endforeach; ?>
    <?php for ($x = 0; $x < 3; $x++): ?><tr><td><?= count($roster) + $x + 1 ?></td><td></td><td></td><td>Guest</td><?php if ($recorded): ?><td></td><?php endif; ?><td class="sig"></td></tr><?php endfor; ?>
  </tbody></table>
  <div class="signs one"><div><span></span>Chair: <?= e($m['organizer_name']) ?></div></div>
</article>
