<?php
use App\Controllers\OwnerController;
$events = OwnerController::EVENTS;
?>
<div class="head"><div><div class="eyebrow">Platform owner</div><h1>Overview</h1><p class="muted">Every company on <?= e(config('app.name')) ?>.</p></div>
  <a class="btn" href="<?= e(path('owner/companies')) ?>"><?= icon('building', 15) ?>All companies</a></div>

<div class="stats five">
  <div class="stat"><b><?= $stats['companies'] ?></b><span>Companies</span><em><?= $stats['new30'] ?> new in 30 days<?= $stats['suspended'] ? ' · ' . $stats['suspended'] . ' suspended' : '' ?></em></div>
  <div class="stat"><b><?= $stats['users'] ?></b><span>Active users</span><em><?= $stats['active30'] ?> signed in within 30 days · <?= $stats['invited'] ?> invited</em></div>
  <div class="stat"><b><?= $stats['meetings'] ?></b><span>Meetings</span><em><?= $stats['meetings30'] ?> planned in 30 days</em></div>
  <div class="stat <?= $stats['overdue'] ? 'alert' : '' ?>"><b><?= $stats['open'] ?></b><span>Open action items</span><em><?= $stats['overdue'] ?> overdue</em></div>
  <div class="stat <?= $stats['mailFailed'] ? 'alert' : '' ?>"><b><?= $stats['mailFailed'] ?></b><span>Failed messages</span><em>last 7 days</em></div>
</div>

<div class="grid-even">
  <div class="panel"><div class="panel-h"><h2>Newest companies</h2>
    <span class="faint small"><?php foreach (OwnerController::PLANS as $k => $l): ?><?= e($l) ?> <?= (int)($plans[$k] ?? 0) ?> &nbsp;<?php endforeach; ?></span></div>
    <?php if (!$recent): ?><div class="panel-b"><div class="empty">No companies yet. They appear here when someone registers.</div></div><?php else: ?>
    <div class="tbl-wrap"><table class="compact"><thead><tr><th>Company</th><th class="num">Members</th><th>Plan</th><th>Joined</th></tr></thead><tbody>
    <?php foreach ($recent as $c): ?>
      <tr><td><a href="<?= e(path('owner/companies/' . $c['id'])) ?>"><b><?= e($c['name']) ?></b></a>
          <?php if ($c['status'] === 'suspended'): ?><span class="pill s-susp">Suspended</span><?php endif; ?>
          <div class="sub"><?= e($c['admin_email'] ?? '—') ?></div></td>
        <td class="num mono"><?= (int)$c['members'] ?></td>
        <td><?= e(OwnerController::PLANS[$c['plan']] ?? ucfirst($c['plan'])) ?></td>
        <td class="faint"><?= e(fmt_date($c['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>

  <div class="panel"><div class="panel-h"><h2>Owner activity</h2></div>
    <?php if (!$log): ?><div class="panel-b"><div class="empty">Nothing yet.</div></div><?php else: ?>
    <div class="tbl-wrap"><table class="compact"><thead><tr><th>What</th><th>Where</th><th>When (UTC)</th></tr></thead><tbody>
    <?php foreach ($log as $l): ?>
      <tr><td><?= e($events[$l['event']] ?? $l['event']) ?></td>
        <td class="faint"><?= $l['org_id'] ? '<a href="' . e(path('owner/companies/' . $l['org_id'])) . '">' . e($l['org_name'] ?? '#' . $l['org_id']) . '</a>' : '—' ?><?= $l['user_email'] ? ' · ' . e($l['user_email']) : '' ?></td>
        <td class="faint mono small"><?= e(substr($l['created_at'], 0, 16)) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>
