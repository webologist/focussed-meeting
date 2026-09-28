<?php
use App\Services\Meetings;
$maxT = max(1, ...array_map(fn($p) => $p['total'], $per ?: [['total' => 1]]));
// Weekly chart geometry
$W = 480; $H = 230; $L = 26; $R = 8; $T = 10; $B = 26;
$maxY = max(2, ...array_map(fn($w) => max($w['created'], $w['completed']), $weeks));
$step = $maxY <= 4 ? 1 : ($maxY <= 8 ? 2 : ($maxY <= 20 ? 5 : 10));
$top = (int)ceil($maxY / $step) * $step;
$y = fn($v) => $T + ($H - $T - $B) * (1 - $v / $top);
$bw = ($W - $L - $R) / 8; $barW = min(18, $bw * 0.28);
$bar = function ($v, $x, $color) use ($y, $barW) {
    if (!$v) return '';
    $y0 = $y(0); $yv = $y($v);
    return sprintf('<path d="M%.1f,%.1f V%.1f q0,-4 4,-4 h%.1f q4,0 4,4 V%.1f Z" fill="%s"/>', $x, $y0, $yv + 4, $barW - 8, $y0, $color);
};
?>
<div class="head">
  <div><div class="eyebrow">Reports</div><h1>Meeting follow-through</h1><p class="muted">How reliably decisions turn into completed work.</p></div>
  <div class="row">
    <div class="tabs"><?php foreach (['30' => '30 days', '90' => '90 days', 'all' => 'All time'] as $k => $l): ?><a class="tabbtn <?= $period === (string)$k ? 'on' : '' ?>" href="<?= e(path('reports', ['period' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
    <a class="btn" href="<?= e(path('reports/export')) ?>"><?= icon('dl', 15) ?>Export CSV</a>
  </div>
</div>
<div class="stats five">
  <div class="stat"><b><?= count($held) ?></b><span>Meetings held</span><em><?= $pct(count($fullMinutes), count($held)) ?>% with full minutes</em></div>
  <div class="stat"><b><?= count($acts) ?></b><span>Action items</span><em><?= count($done) ?> completed</em></div>
  <div class="stat"><b><?= $pct(count($done), count($acts)) ?>%</b><span>Completion rate</span><em><?= count($acts) - count($done) ?> still open</em></div>
  <div class="stat"><b><?= $pct(count($onTime), count($done)) ?>%</b><span>Done by original deadline</span><em>of completed items</em></div>
  <div class="stat <?= $late ? 'alert' : '' ?>"><b><?= count($late) ?></b><span>Overdue now</span><em><?= plural(count($moved), 'deadline') ?> moved</em></div>
</div>
<div class="grid-even">
  <div class="panel"><div class="panel-h"><h2>Status by person</h2>
    <div class="legend"><span><i style="background:var(--st-good)"></i>Done</span><span><i style="background:var(--c1)"></i>Open</span><span><i style="background:var(--st-crit)"></i>Overdue</span></div></div>
    <div class="panel-b">
      <?php if (!$per): ?><div class="empty">No action items in this period.</div><?php else: ?>
      <div class="hbars chart">
        <?php foreach ($per as $p): ?>
        <div class="hbar"><span class="nm"><?= e($p['name']) ?></span><span class="track">
          <?php foreach ([['done', 'var(--st-good)', 'Done'], ['open', 'var(--c1)', 'Open, on track'], ['overdue', 'var(--st-crit)', 'Overdue']] as [$k, $c, $l]): if (!$p[$k]) continue; ?>
            <span class="seg" style="width:<?= round($p[$k] / $maxT * 100, 2) ?>%;background:<?= $c ?>" data-tip="<b><?= e(e($p['name'])) ?></b><?= $l ?>: <?= $p[$k] ?> of <?= $p['total'] ?>" title="<?= e($p['name'] . ' · ' . $l . ': ' . $p[$k]) ?>"></span>
          <?php endforeach; ?></span><span class="tot"><?= $p['total'] ?></span></div>
        <?php endforeach; ?>
      </div><?php endif; ?>
    </div></div>
  <div class="panel"><div class="panel-h"><h2>Created vs completed per week</h2>
    <div class="legend"><span><i style="background:var(--c1)"></i>Created</span><span><i style="background:var(--c2)"></i>Completed</span></div></div>
    <div class="panel-b chart">
      <svg class="vchart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Action items created and completed per week over the last 8 weeks">
        <?php for ($v = 0; $v <= $top; $v += $step): ?><line class="<?= $v === 0 ? 'base' : 'gl' ?>" x1="<?= $L ?>" x2="<?= $W - $R ?>" y1="<?= $y($v) ?>" y2="<?= $y($v) ?>"/><text x="<?= $L - 6 ?>" y="<?= $y($v) + 4 ?>" text-anchor="end"><?= $v ?></text><?php endfor; ?>
        <?php foreach ($weeks as $i => $w): $cx = $L + $bw * $i + $bw / 2; ?>
          <rect class="hit" x="<?= $L + $bw * $i + 2 ?>" y="<?= $T ?>" width="<?= $bw - 4 ?>" height="<?= $H - $T - $B ?>" rx="6" data-tip="<b>Week of <?= e(e($w['range'])) ?></b>Created: <?= $w['created'] ?><br>Completed: <?= $w['completed'] ?>"><title>Week of <?= e($w['range']) ?>: created <?= $w['created'] ?>, completed <?= $w['completed'] ?></title></rect>
          <?= $bar($w['created'], $cx - $barW - 1, 'var(--c1)') ?><?= $bar($w['completed'], $cx + 1, 'var(--c2)') ?>
          <text x="<?= $cx ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= e($w['label']) ?></text>
        <?php endforeach; ?>
      </svg>
    </div></div>
</div>
<div class="panel"><div class="panel-h"><h2>By person</h2><span class="faint small">Slip = average days late on items finished after the original deadline</span></div>
  <div class="tbl-wrap"><table class="compact"><thead><tr><th>Person</th><th class="num">Assigned</th><th class="num">Done</th><th>On time</th><th class="num">Overdue</th><th class="num">Deadlines moved</th><th class="num">Avg slip</th></tr></thead><tbody>
    <?php if (!$per): ?><tr><td colspan="7" class="empty">No data for this period.</td></tr><?php endif; ?>
    <?php foreach ($per as $p): $ot = $pct($p['ontime'], $p['done']); ?>
    <tr><td><?= person_chip($p) ?></td><td class="num mono"><?= $p['total'] ?></td><td class="num mono"><?= $p['done'] ?></td>
      <td><?php if ($p['done']): ?><span class="bar-in"><span class="mini"><i style="width:<?= $ot ?>%"></i></span><span class="mono"><?= $ot ?>%</span></span><?php else: ?><span class="faint">—</span><?php endif; ?></td>
      <td class="num mono" style="<?= $p['overdue'] ? 'color:var(--high);font-weight:600' : '' ?>"><?= $p['overdue'] ?: '—' ?></td><td class="num mono"><?= $p['moved'] ?: '—' ?></td>
      <td class="num mono"><?= $p['lateDone'] ? round($p['slipDays'] / $p['lateDone'], 1) . ' d' : '—' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div></div>
<div class="panel"><div class="panel-h"><h2>By meeting</h2></div>
  <div class="tbl-wrap"><table class="compact"><thead><tr><th>Meeting</th><th>Organiser</th><th class="num">Attended</th><th class="num">Actions</th><th class="num">Done</th><th class="num">Overdue</th><th>Minutes</th></tr></thead><tbody>
    <?php if (!$meetings): ?><tr><td colspan="7" class="empty">No meetings in this period.</td></tr><?php endif; ?>
    <?php foreach ($meetings as $m): $x = array_filter($acts, fn($a) => (int)$a['meeting_id'] === (int)$m['id']); [$label, $cls] = Meetings::status($m, (int)$m['blank_points']); ?>
    <tr><td><a class="linkish title" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= e($m['title']) ?></a><div class="sub"><?= e(fmt_date($m['meeting_date'])) ?></div></td><td><?= e($m['organizer_name']) ?></td>
      <td class="num mono"><?= (int)$m['attended'] ?>/<?= (int)$m['invited'] ?></td><td class="num mono"><?= count($x) ?></td><td class="num mono"><?= count(array_filter($x, fn($a) => $a['status'] === 'done')) ?></td>
      <td class="num mono"><?= count(array_filter($x, fn($a) => \App\Services\Actions::isOverdue($a))) ?: '—' ?></td><td><span class="pill <?= $cls ?>"><?= e($label) ?></span></td></tr>
    <?php endforeach; ?>
  </tbody></table></div></div>
<div class="tip" id="tip" hidden></div>
