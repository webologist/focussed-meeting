<?php
$filtered = $q !== '' || $project || $from !== '' || $to !== '';
?>
<div class="head">
  <div style="min-width:0"><div class="eyebrow">Across all your meetings</div><h1 style="margin-top:4px">Decision log</h1>
    <p class="muted">Every decision recorded in saved minutes. Add one in the <b>Decision</b> box under any agenda point.</p></div>
</div>

<div class="panel">
  <form class="panel-h" method="get" action="<?= e(path('decisions')) ?>" style="flex-wrap:wrap;gap:8px">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search decisions, points or meetings" aria-label="Search" style="min-width:220px;flex:1">
    <?php if ($projects): ?><select name="project" data-autosubmit aria-label="Filter by project"><option value="">All projects</option>
      <?php foreach ($projects as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= $project === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <input type="date" name="from" value="<?= e($from) ?>" data-autosubmit aria-label="From date">
    <input type="date" name="to" value="<?= e($to) ?>" data-autosubmit aria-label="To date">
    <button class="btn sm" type="submit">Search</button>
    <?php if ($filtered): ?><a class="btn ghost sm" href="<?= e(path('decisions')) ?>">Clear</a><?php endif; ?>
  </form>
  <div class="list">
    <?php if (!$rows): ?><div class="empty" style="padding:18px"><?= $filtered ? 'No decisions match.' : 'No decisions yet. They appear here once minutes with a decision are saved.' ?></div><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <div class="li"><div style="min-width:0">
      <div class="title" style="white-space:pre-wrap"><?= e($r['decision']) ?></div>
      <div class="meta"><span>Point: <?= e($r['point_title']) ?></span>
        <a href="<?= e(path('meetings/' . $r['meeting_id'])) ?>"><?= e($r['meeting_title']) ?></a>
        <?php if ($r['project_name']): ?><span class="pill s-project"><?= e($r['project_name']) ?></span><?php endif; ?>
        <span>Host: <?= e($r['organizer_name']) ?></span></div></div>
      <div class="mono small" style="white-space:nowrap"><?= e(fmt_date($r['meeting_date'])) ?></div></div>
    <?php endforeach; ?>
  </div>
</div>
