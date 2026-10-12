<?php
// One formal invitation letter per invitee (the host excluded), each on its own printed page.
$others = array_values(array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
$day = gmdate('l', strtotime($m['meeting_date'] . ' 00:00:00 UTC'));
$where = $m['platform'] === 'inperson' ? ($m['location'] ?: 'the office (room to be confirmed)') : platform_label($m['platform']) . ($m['join_url'] ? ' (' . $m['join_url'] . ')' : '');
?>
<?php if (!$others): ?><article class="paper"><p>There are no invitees on this meeting yet.</p></article><?php endif; ?>
<?php foreach ($others as $n => $i): ?>
<article class="paper letter<?= $n ? ' page-break' : '' ?>">
  <div class="rule"><div><div class="pk"><?= e(user()['org_name']) ?></div><h1 style="margin-top:4px">Personal invitation</h1></div>
    <div class="meta" style="text-align:right"><?= e(fmt_date(today())) ?><br>Ref FM-<?= (int)$m['id'] ?>/<?= $n + 1 ?></div></div>
  <p>Dear <?= e($i['name']) ?><?= $i['title'] ? ',<br><span class="meta">' . e($i['title']) . '</span>' : ',' ?></p>
  <p>You are cordially invited to attend <b><?= e($m['title']) ?></b><?= $project ? ' for the <b>' . e($project['name']) . '</b> project' : '' ?>, to be held on <b><?= e($day) ?>, <?= e(fmt_date($m['meeting_date'])) ?></b> at <b><?= e(fmt_time($m['start_time'])) ?></b> (<?= (int)$m['duration_min'] ?> minutes) at <?= e($where) ?>.</p>
  <?php if ($m['personal_note']): ?><div class="letter-note"><?= e($m['personal_note']) ?></div><?php endif; ?>
  <div><div class="pk" style="margin-bottom:8px">Agenda</div>
    <ol><?php foreach ($agenda as $p): ?><li><b><?= e($p['title']) ?></b><?= (int)$p['presenter_id'] === (int)$i['id'] ? ' <span class="meta">· presented by you</span>' : '' ?></li><?php endforeach; ?></ol></div>
  <p>Kindly confirm your attendance<?= $i['status'] === 'active' ? ' in ' . e(config('app.name')) : '' ?>. Minutes and action items will be shared after the meeting.</p>
  <div class="signs one"><div><span></span><?= e($m['organizer_name']) ?><br><span class="meta">Host</span></div></div>
</article>
<?php endforeach; ?>
