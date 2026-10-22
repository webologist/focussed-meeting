<?php
use App\Services\Actions;
// $t translates text when a language is chosen (it returns the text unchanged otherwise). Names are never translated.
$t = $t ?? fn($s) => (string)$s;
$present = array_filter($invitees, fn($i) => (int)$i['attended'] === 1);
$roster = array_merge(array_filter($invitees, fn($i) => (int)$i['id'] === (int)$m['organizer_id']), array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
$td = 'style="padding:6px 8px;border-bottom:1px solid #E3E7EC;font-size:13px;vertical-align:top"';
$day = gmdate('l', strtotime($m['meeting_date'] . ' 00:00:00 UTC'));
$when = $day . ', ' . fmt_date($m['meeting_date']) . ' at ' . fmt_time($m['start_time']);
?>
<p style="margin:0 0 12px;color:#4A5663"><?= e($t(fmt_date($m['meeting_date']) . ', ' . fmt_time($m['start_time']))) ?> · <?= e(platform_label($m['platform'])) ?><?= !empty($project) ? ' · ' . e($t('Project')) . ': ' . e($t($project['name'])) : '' ?> · <?= e($t('Chaired by')) ?> <?= e($m['organizer_name']) ?></p>
<p style="margin:0 0 6px;font-weight:700"><?= e($t('Attendance')) ?></p>
<p style="margin:0 0 8px;color:#4A5663"><?= e($t('The meeting was held on ' . $when . ', chaired by ' . $m['organizer_name'] . '. ' . count($present) . ' of ' . count($invitees) . ' invited members were present.')) ?></p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 16px">
  <tr><td <?= $td ?>><b><?= e($t('Name')) ?></b></td><td <?= $td ?>><b><?= e($t('Designation')) ?></b></td><td <?= $td ?>><b><?= e($t('Role')) ?></b></td><td <?= $td ?>><b><?= e($t('Attendance')) ?></b></td></tr>
  <?php foreach ($roster as $i): ?><tr><td <?= $td ?>><?= e($i['name']) ?></td><td <?= $td ?>><?= e($t($i['title'] ?: '—')) ?></td><td <?= $td ?>><?= e($t((int)$i['id'] === (int)$m['organizer_id'] ? 'Chair' : 'Member')) ?></td><td <?= $td ?>><?= e($t((int)$i['attended'] === 1 ? 'Present' : 'Absent')) ?></td></tr><?php endforeach; ?>
</table>
<p style="margin:0 0 6px;font-weight:700"><?= e($t('Agenda and discussion')) ?></p>
<ol style="padding-left:20px;margin:0 0 16px">
<?php foreach ($agenda as $p): ?><li style="margin-bottom:10px"><b><?= e($t($p['title'])) ?></b><div style="color:#4A5663;white-space:pre-wrap"><?= e($t($p['discussion'] ?: 'No minutes recorded.')) ?></div></li><?php endforeach; ?>
</ol>
<?php if (!empty($declined)): ?>
<p style="margin:0 0 6px;font-weight:700"><?= e($t('Points requested but not added')) ?></p>
<ol style="padding-left:20px;margin:0 0 16px">
<?php foreach ($declined as $d): ?><li style="margin-bottom:10px"><b><?= e($t($d['title'])) ?></b> <span style="color:#7B8794"><?= e($t('requested by')) ?> <?= e($d['requester_name'] ?? '') ?></span><div style="color:#4A5663"><?= e($t('Declined by')) ?> <?= e($d['reviewer_name'] ?? $t('the chair')) ?><?= $d['review_note'] ? ': ' . e($t($d['review_note'])) : '' ?></div></li><?php endforeach; ?>
</ol>
<?php endif; ?>
<p style="margin:0 0 6px;font-weight:700"><?= e($t('Action items')) ?></p>
<?php if (!$actions): ?><p style="color:#7B8794"><?= e($t('No action items recorded.')) ?></p><?php else: ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">
  <tr><td <?= $td ?>><b><?= e($t('Action')) ?></b></td><td <?= $td ?>><b><?= e($t('Responsible')) ?></b></td><td <?= $td ?>><b><?= e($t('Priority')) ?></b></td><td <?= $td ?>><b><?= e($t('Deadline')) ?></b></td></tr>
  <?php foreach ($actions as $x): ?><tr><td <?= $td ?>><?= e($t($x['title'])) ?></td><td <?= $td ?>><?= e($x['owner_name']) ?></td><td <?= $td ?>><?= e($t(ucfirst($x['priority']))) ?></td><td <?= $td ?>><?= e($t(fmt_date(Actions::effDeadline($x)))) ?></td></tr><?php endforeach; ?>
</table><?php endif; ?>
<?php if (!empty($signature)): ?><p style="margin-top:18px;color:#4A5663"><?= nl2br(e($signature)) ?></p><?php endif; ?>
