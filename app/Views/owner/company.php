<?php
use App\Controllers\OwnerController;
$link = $_SESSION['_owner_link'] ?? null; unset($_SESSION['_owner_link']);
$suspended = $org['status'] === 'suspended';
$statusPill = ['active' => '', 'invited' => '<span class="pill s-pending">Invited</span>', 'disabled' => '<span class="pill s-neutral">Access off</span>'];
$providers = ['smtp' => 'Email (SMTP)', 'google' => 'Google Meet', 'microsoft' => 'Microsoft Teams', 'whatsapp' => 'WhatsApp'];
?>
<div class="head">
  <div><div class="eyebrow"><a href="<?= e(path('owner/companies')) ?>">Companies</a></div>
    <h1><?= e($org['name']) ?> <?= $suspended ? '<span class="pill s-susp" style="vertical-align:4px">Suspended</span>' : '' ?></h1>
    <p class="muted">Joined <?= e(fmt_date($org['created_at'])) ?> · <?= e($org['timezone']) ?> · ID <?= (int)$org['id'] ?></p></div>
  <form method="post" action="<?= e(path('owner/companies/' . $org['id'] . '/status')) ?>" class="inline"><?= csrf_field() ?>
    <input type="hidden" name="status" value="<?= $suspended ? 'active' : 'suspended' ?>">
    <?php if ($suspended): ?><button class="btn primary" type="submit">Restore company</button>
    <?php else: ?><button class="btn" type="submit" data-confirm="Suspend <?= e($org['name']) ?>? Nobody in this company can sign in until you restore it. Their data stays.">Suspend company</button><?php endif; ?>
  </form>
</div>

<?php if ($link): ?>
<div class="link-box" role="status"><span>Password link for <b><?= e($link['for']) ?></b> (works 24 hours, once). We also emailed it. You can share it directly:</span>
  <code id="reset-link"><?= e($link['url']) ?></code><button class="btn sm" type="button" data-copy="#reset-link">Copy link</button></div>
<?php endif; ?>

<div class="stats five">
  <div class="stat"><b><?= count(array_filter($members, fn($m) => $m['status'] !== 'disabled')) ?></b><span>Members</span><em><?= $org['seats_limit'] ? 'of ' . (int)$org['seats_limit'] . ' seats' : 'no seat limit' ?></em></div>
  <div class="stat"><b><?= $counts['meetings'] ?></b><span>Meetings</span><em><?= $counts['completed'] ?> with minutes done</em></div>
  <div class="stat"><b><?= $counts['actions'] ?></b><span>Action items</span><em><?= $counts['open'] ?> open</em></div>
  <div class="stat <?= $counts['overdue'] ? 'alert' : '' ?>"><b><?= $counts['overdue'] ?></b><span>Overdue</span><em>open past deadline</em></div>
  <div class="stat"><b><?= count($integrations) ?></b><span>Integrations</span><em><?= e(implode(', ', array_map(fn($i) => $providers[$i['provider']] ?? $i['provider'], $integrations)) ?: 'none connected') ?></em></div>
</div>

<div class="panel"><div class="panel-h"><h2>Members</h2><span class="faint small">Reset links, roles and access</span></div>
  <div class="tbl-wrap"><table class="compact" style="min-width:760px"><thead><tr><th>Member</th><th>Email</th><th>Role</th><th>Last sign-in</th><th class="num">Open</th><th></th></tr></thead><tbody>
  <?php foreach ($members as $m): ?>
    <tr style="<?= $m['status'] === 'disabled' ? 'opacity:.55' : '' ?>">
      <td><?= person_chip($m, 24) ?> <?= $statusPill[$m['status']] ?></td>
      <td class="faint"><?= e($m['email']) ?></td>
      <td><form method="post" action="<?= e(path('owner/users/' . $m['id'] . '/role')) ?>" class="inline"><?= csrf_field() ?>
        <select name="role" data-autosubmit aria-label="Role for <?= e($m['name']) ?>" style="width:auto">
          <option value="participant" <?= $m['role'] === 'participant' ? 'selected' : '' ?>>Participant</option>
          <option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select></form></td>
      <td class="faint"><?= e($m['last_login_at'] ? fmt_date($m['last_login_at']) : 'Never') ?></td>
      <td class="num mono"><?= (int)$m['open_items'] ?: '—' ?></td>
      <td style="text-align:right;white-space:nowrap">
        <?php if ($m['status'] !== 'disabled'): ?><form method="post" action="<?= e(path('owner/users/' . $m['id'] . '/reset')) ?>" class="inline"><?= csrf_field() ?>
          <button class="btn sm ghost" type="submit" title="Create a one-time link to choose a new password"><?= icon('link', 14) ?>Password link</button></form><?php endif; ?>
        <form method="post" action="<?= e(path('owner/users/' . $m['id'] . '/status')) ?>" class="inline"><?= csrf_field() ?>
          <input type="hidden" name="status" value="<?= $m['status'] === 'disabled' ? 'active' : 'disabled' ?>">
          <button class="btn sm ghost" type="submit" <?= $m['status'] === 'disabled' ? '' : 'data-confirm="Turn off access for ' . e($m['name']) . '?"' ?>><?= $m['status'] === 'disabled' ? 'Restore access' : 'Turn off access' ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$members): ?><tr><td colspan="6"><div class="empty">No members.</div></td></tr><?php endif; ?>
  </tbody></table></div></div>

<div class="grid-even">
  <form class="panel" method="post" action="<?= e(path('owner/companies/' . $org['id'] . '/plan')) ?>"><?= csrf_field() ?>
    <div class="panel-h"><h2>Plan</h2><button class="btn sm primary" type="submit">Save</button></div>
    <div class="panel-b fields">
      <label class="f">Plan<select name="plan"><?php foreach (OwnerController::PLANS as $k => $l): ?><option value="<?= e($k) ?>" <?= $org['plan'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="f">Seat limit<input type="number" name="seats_limit" min="1" max="100000" value="<?= e($org['seats_limit'] ?? '') ?>" placeholder="No limit"><span class="hint">Leave empty for unlimited. Admins can’t invite past this number.</span></label>
    </div></form>

  <div class="panel"><div class="panel-h"><h2>Recent activity</h2></div>
    <?php if (!$activity && !$log): ?><div class="panel-b"><div class="empty">No activity yet.</div></div><?php else: ?>
    <div class="tbl-wrap"><table class="compact"><thead><tr><th>Event</th><th>By</th><th>When (UTC)</th></tr></thead><tbody>
      <?php foreach ($log as $l): ?><tr><td><?= e(OwnerController::EVENTS[$l['event']] ?? $l['event']) ?><?= $l['user_email'] ? ' · ' . e($l['user_email']) : '' ?></td><td><span class="pill p-admin">Owner</span></td><td class="faint mono small"><?= e(substr($l['created_at'], 0, 16)) ?></td></tr><?php endforeach; ?>
      <?php foreach ($activity as $a): ?><tr><td><?= e($a['event']) ?></td><td class="faint"><?= e($a['name'] ?? '—') ?></td><td class="faint mono small"><?= e(substr($a['created_at'], 0, 16)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>
