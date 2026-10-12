<?php use App\Controllers\OwnerController; ?>
<div class="head"><div><div class="eyebrow">Platform owner</div><h1>Companies</h1><p class="muted"><?= plural(count($companies), 'company', 'companies') ?><?= $q !== '' || $status !== 'all' ? ' match your filters' : '' ?>.</p></div></div>

<form class="owner-search" method="get" action="<?= e(path('owner/companies')) ?>" role="search">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search company, member name or email" aria-label="Search companies">
  <select name="status" aria-label="Status" data-autosubmit>
    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
    <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
  </select>
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== '' || $status !== 'all'): ?><a class="btn ghost" href="<?= e(path('owner/companies')) ?>">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <?php if (!$companies): ?><div class="panel-b"><div class="empty"><?= $q !== '' ? 'No company matches “' . e($q) . '”.' : 'No companies yet.' ?></div></div><?php else: ?>
  <div class="tbl-wrap"><table class="compact" style="min-width:760px"><thead><tr>
    <th>Company</th><th>Plan</th><th class="num">Members</th><th class="num">Meetings</th><th class="num">Open items</th><th>Last active</th><th>Joined</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($companies as $c): ?>
    <tr style="<?= $c['status'] === 'suspended' ? 'opacity:.7' : '' ?>">
      <td><a href="<?= e(path('owner/companies/' . $c['id'])) ?>"><b><?= e($c['name']) ?></b></a><div class="sub"><?= e($c['admin_email'] ?? 'No admin') ?></div></td>
      <td><?= e(OwnerController::PLANS[$c['plan']] ?? ucfirst($c['plan'])) ?><?php if ($c['seats_limit']): ?><div class="sub"><?= (int)$c['seats_limit'] ?> seats</div><?php endif; ?></td>
      <td class="num mono"><?= (int)$c['members'] ?></td>
      <td class="num mono"><?= (int)$c['meetings'] ?: '—' ?></td>
      <td class="num mono"><?= (int)$c['open_items'] ?: '—' ?></td>
      <td class="faint"><?= e($c['last_active'] ? fmt_date($c['last_active']) : 'Never') ?></td>
      <td class="faint"><?= e(fmt_date($c['created_at'])) ?></td>
      <td><?= $c['status'] === 'suspended' ? '<span class="pill s-susp">Suspended</span>' : '<span class="pill s-done">Active</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
