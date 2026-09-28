<?php
use App\Controllers\AccountController;
$admins = array_filter($members, fn($m) => $m['role'] === 'admin' && $m['status'] === 'active');
$statusPill = ['active' => '', 'invited' => '<span class="pill s-pending">Invited</span>', 'disabled' => '<span class="pill s-neutral">Access off</span>'];
?>
<div class="head"><div><div class="eyebrow">Settings</div><h1>Team &amp; roles</h1><p class="muted"><?= e($org['name']) ?> · <?= plural(count(array_filter($members, fn($m) => $m['status'] !== 'disabled')), 'member') ?></p></div></div>
<p class="role-note"><b>Admin</b> creates meetings, assigns tasks, sends reminders to others, manages integrations and roles, and sees reports. <b>Participant</b> sees their own meetings and tasks, writes minutes, adds notes and images, and sets personal reminders. The organiser of a meeting can manage that meeting.</p>

<?php if (is_admin()): ?>
<form class="panel" method="post" action="<?= e(path('team/invite')) ?>"><?= csrf_field() ?>
  <div class="panel-h"><h2>Invite a member</h2><span class="faint small">They’ll get an email to register</span></div>
  <div class="panel-b"><div class="team-add" style="grid-template-columns:minmax(0,1fr) minmax(0,1.3fr) minmax(0,1fr) 130px auto">
    <input type="text" name="name" required placeholder="Name" aria-label="Name">
    <input type="email" name="email" required placeholder="Email" aria-label="Email">
    <input type="tel" name="phone" placeholder="WhatsApp (optional)" aria-label="WhatsApp number">
    <select name="role" aria-label="Role"><option value="participant">Participant</option><option value="admin">Admin</option></select>
    <button class="btn primary" type="submit"><?= icon('send', 15) ?>Invite</button>
  </div></div></form>
<?php endif; ?>

<div class="panel"><div class="panel-h"><h2>Members</h2><span class="faint small"><?= count($admins) ?> admin<?= count($admins) === 1 ? '' : 's' ?></span></div>
  <div class="tbl-wrap"><table class="compact" style="min-width:640px"><thead><tr><th>Member</th><th>Email</th><th class="num">Open items</th><th>Role</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($members as $m): $me = (int)$m['id'] === uid(); ?>
    <tr style="<?= $m['status'] === 'disabled' ? 'opacity:.55' : '' ?>">
      <td><?= person_chip($m, 24) ?> <?= $statusPill[$m['status']] ?><?php if ($m['title']): ?><div class="sub"><?= e($m['title']) ?></div><?php endif; ?></td>
      <td class="faint"><?= e($m['email']) ?></td>
      <td class="num mono"><?= (int)$m['open_items'] ?: '—' ?></td>
      <td><?php if (is_admin() && $m['status'] !== 'disabled'): ?>
        <form method="post" action="<?= e(path('team/' . $m['id'] . '/role')) ?>" class="inline"><?= csrf_field() ?><select name="role" data-autosubmit aria-label="Role for <?= e($m['name']) ?>" style="width:auto">
          <option value="participant" <?= $m['role'] === 'participant' ? 'selected' : '' ?>>Participant</option><option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select></form>
        <?php else: ?><span class="pill <?= $m['role'] === 'admin' ? 'p-admin' : 's-neutral' ?>"><?= $m['role'] === 'admin' ? 'Admin' : 'Participant' ?></span><?php endif; ?></td>
      <?php if (is_admin()): ?><td style="text-align:right;white-space:nowrap">
        <?php if ($m['status'] === 'invited'): ?><form method="post" action="<?= e(path('team/' . $m['id'] . '/resend')) ?>" class="inline"><?= csrf_field() ?><button class="btn sm ghost" type="submit">Resend invite</button></form><?php endif; ?>
        <?php if (!$me): ?><form method="post" action="<?= e(path('team/' . $m['id'] . '/status')) ?>" class="inline"><?= csrf_field() ?>
          <input type="hidden" name="status" value="<?= $m['status'] === 'disabled' ? 'active' : 'disabled' ?>">
          <button class="btn sm ghost" type="submit" <?= $m['status'] === 'disabled' ? '' : 'data-confirm="Turn off access for ' . e($m['name']) . '? Their items stay in place."' ?>><?= $m['status'] === 'disabled' ? 'Restore access' : 'Turn off access' ?></button></form><?php endif; ?>
      </td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>

<?php if (is_admin()): ?>
<form class="panel" method="post" action="<?= e(path('team/company')) ?>"><?= csrf_field() ?>
  <div class="panel-h"><h2>Company</h2><button class="btn sm primary" type="submit">Save</button></div>
  <div class="panel-b fields">
    <label class="f">Company name<input type="text" name="name" value="<?= e($org['name']) ?>" required maxlength="150"></label>
    <label class="f">Default time zone<select name="timezone"><?php foreach (AccountController::TIMEZONES as $z): ?><option <?= $org['timezone'] === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></label>
    <label class="f">Plan<input type="text" value="<?= e(ucfirst($org['plan'])) ?>" disabled></label>
  </div></form>
<?php else: ?>
<p class="muted small">Only admins can change roles. Admins: <?= e(implode(', ', array_column($admins, 'name'))) ?>.</p>
<?php endif; ?>
