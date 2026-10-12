<?php
use App\Services\Actions;
$present = array_filter($invitees, fn($i) => (int)$i['attended'] === 1);
$roster = array_merge(array_filter($invitees, fn($i) => (int)$i['id'] === (int)$m['organizer_id']), array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
$td = 'style="padding:6px 8px;border-bottom:1px solid #E3E7EC;font-size:13px;vertical-align:top"';
$day = gmdate('l', strtotime($m['meeting_date'] . ' 00:00:00 UTC'));
?>
<p style="margin:0 0 12px;color:#4A5663"><?= e(fmt_date($m['meeting_date'])) ?>, <?= e(fmt_time($m['start_time'])) ?> · <?= e(platform_label($m['platform'])) ?><?= !empty($project) ? ' · Project: ' . e($project['name']) : '' ?> · Chaired by <?= e($m['organizer_name']) ?></p>
<p style="margin:0 0 6px;font-weight:700">Attendance</p>
<p style="margin:0 0 8px;color:#4A5663">The meeting was held on <?= e($day) ?>, <?= e(fmt_date($m['meeting_date'])) ?> at <?= e(fmt_time($m['start_time'])) ?>, chaired by <?= e($m['organizer_name']) ?>. <?= count($present) ?> of <?= count($invitees) ?> invited members were present.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 16px">
  <tr><td <?= $td ?>><b>Name</b></td><td <?= $td ?>><b>Designation</b></td><td <?= $td ?>><b>Role</b></td><td <?= $td ?>><b>Attendance</b></td></tr>
  <?php foreach ($roster as $i): ?><tr><td <?= $td ?>><?= e($i['name']) ?></td><td <?= $td ?>><?= e($i['title'] ?: '—') ?></td><td <?= $td ?>><?= (int)$i['id'] === (int)$m['organizer_id'] ? 'Chair' : 'Member' ?></td><td <?= $td ?>><?= (int)$i['attended'] === 1 ? 'Present' : 'Absent' ?></td></tr><?php endforeach; ?>
</table>
<p style="margin:0 0 6px;font-weight:700">Agenda and discussion</p>
<ol style="padding-left:20px;margin:0 0 16px">
<?php foreach ($agenda as $p): ?><li style="margin-bottom:10px"><b><?= e($p['title']) ?></b><div style="color:#4A5663;white-space:pre-wrap"><?= e($p['discussion'] ?: 'No minutes recorded.') ?></div></li><?php endforeach; ?>
</ol>
<p style="margin:0 0 6px;font-weight:700">Action items</p>
<?php if (!$actions): ?><p style="color:#7B8794">No action items recorded.</p><?php else: ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">
  <tr><td <?= $td ?>><b>Action</b></td><td <?= $td ?>><b>Responsible</b></td><td <?= $td ?>><b>Priority</b></td><td <?= $td ?>><b>Deadline</b></td></tr>
  <?php foreach ($actions as $x): ?><tr><td <?= $td ?>><?= e($x['title']) ?></td><td <?= $td ?>><?= e($x['owner_name']) ?></td><td <?= $td ?>><?= e(ucfirst($x['priority'])) ?></td><td <?= $td ?>><?= e(fmt_date(Actions::effDeadline($x))) ?></td></tr><?php endforeach; ?>
</table><?php endif; ?>
<?php if (!empty($signature)): ?><p style="margin-top:18px;color:#4A5663"><?= nl2br(e($signature)) ?></p><?php endif; ?>
