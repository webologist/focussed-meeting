<?php
use App\Controllers\AccountController;
$admins = array_filter($members, fn($m) => $m['role'] === 'admin' && $m['status'] === 'active');
$statusPill = ['active' => '', 'invited' => '<span class="pill s-pending">Invited</span>', 'disabled' => '<span class="pill p-high">Blocked</span>'];
$takers = array_filter($members, fn($m) => $m['status'] === 'active');
$nu = $_SESSION['_team_new'] ?? []; unset($_SESSION['_team_new']);
?>
<div class="head"><div><div class="eyebrow">Settings</div><h1>Team &amp; roles</h1><p class="muted"><?= e($org['name']) ?> · <?= plural(count(array_filter($members, fn($m) => $m['status'] !== 'disabled')), 'member') ?></p></div></div>
<p class="role-note"><b>Admin</b> assigns tasks to anyone, invites new people or creates their account with a password, sends reminders to others, manages integrations and roles, and sees reports. <b>Participant</b> plans meetings for their projects, sees their own meetings and tasks, writes minutes, asks the host to add discussion points, hands tasks to teammates, and sets personal reminders. The <b>host</b> (organiser) of a meeting manages it, prints its documents and approves completed action items.</p>

<?php if (is_admin()): ?>
<form class="panel" method="post" action="<?= e(path('team/invite')) ?>"><?= csrf_field() ?>
  <div class="panel-h"><h2>Invite a member</h2><span class="faint small">They’ll get an email to register</span></div>
  <div class="panel-b"><div class="team-add invite">
    <input type="text" name="name" required placeholder="Name" aria-label="Name">
    <input type="email" name="email" required placeholder="Email" aria-label="Email">
    <input type="tel" name="phone" placeholder="WhatsApp (optional)" aria-label="WhatsApp number">
    <select name="role" aria-label="Role"><option value="participant">Participant</option><option value="admin">Admin</option></select>
    <button class="btn primary" type="submit"><?= icon('send', 15) ?>Invite</button>
  </div></div></form>
<?php endif; ?>

<?php if (is_admin()): ?>
<form class="panel" method="post" action="<?= e(path('team/create')) ?>" id="create-user" autocomplete="off"><?= csrf_field() ?>
  <div class="panel-h"><h2>Create a user with a password</h2><span class="faint small">They can sign in straight away, no email needed</span></div>
  <div class="panel-b stack">
    <div class="fields">
      <label class="f">Name<input type="text" name="name" value="<?= e($nu['name'] ?? '') ?>" required maxlength="120" autocomplete="off"></label>
      <label class="f">Email (their sign-in)<input type="email" name="email" value="<?= e($nu['email'] ?? '') ?>" required autocomplete="off"></label>
      <label class="f">WhatsApp <span class="hint">optional</span><input type="tel" name="phone" value="<?= e($nu['phone'] ?? '') ?>" autocomplete="off"></label>
      <label class="f">Role<select name="role"><option value="participant" <?= ($nu['role'] ?? '') !== 'admin' ? 'selected' : '' ?>>Participant</option><option value="admin" <?= ($nu['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option></select></label>
    </div>
    <label class="f">Password <span class="hint">at least 8 characters, letters and numbers</span>
      <span class="pw-row"><input type="password" name="password" id="new-user-pw" required minlength="8" autocomplete="new-password">
        <button type="button" class="btn sm" data-toggle-password="#new-user-pw">Show</button>
        <button type="button" class="btn sm" data-gen-password="#new-user-pw">Generate</button></span></label>
    <div class="row"><button class="btn primary" type="submit"><?= icon('plus', 15) ?>Create user</button><span class="faint small">Copy the password before you save. It isn’t shown again, and you can set a new one later.</span></div>
  </div>
</form>
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
          <button class="btn sm ghost" type="submit" <?= $m['status'] === 'disabled' ? '' : 'data-confirm="Block ' . e($m['name']) . '? They won’t be able to sign in. Their items stay in place."' ?>><?= $m['status'] === 'disabled' ? 'Unblock' : 'Block' ?></button></form>
          <details class="menu" style="display:inline-block"><summary class="btn sm ghost" aria-label="Set password for <?= e($m['name']) ?>">Set password</summary>
            <form method="post" action="<?= e(path('team/' . $m['id'] . '/password')) ?>" class="menu-pop stack" style="text-align:left;white-space:normal;min-width:280px" autocomplete="off"><?= csrf_field() ?>
              <div><b>New password for <?= e($m['name']) ?></b><div class="sub">Replaces their current password<?= $m['status'] === 'invited' ? ' and activates their account' : '' ?>.</div></div>
              <span class="pw-row"><input type="password" name="password" id="pw-<?= (int)$m['id'] ?>" required minlength="8" autocomplete="new-password" aria-label="New password">
                <button type="button" class="btn sm" data-toggle-password="#pw-<?= (int)$m['id'] ?>">Show</button>
                <button type="button" class="btn sm" data-gen-password="#pw-<?= (int)$m['id'] ?>">Generate</button></span>
              <div class="row"><button class="btn sm primary" type="submit" data-confirm="Set a new password for <?= e($m['name']) ?>?">Save password</button></div>
            </form></details>
          <details class="menu" style="display:inline-block"><summary class="btn sm ghost" style="color:var(--danger,#b42318)" aria-label="Delete <?= e($m['name']) ?>">Delete</summary>
            <form method="post" action="<?= e(path('team/' . $m['id'] . '/delete')) ?>" class="menu-pop stack" style="text-align:left;white-space:normal;min-width:260px"><?= csrf_field() ?>
              <div><b>Delete <?= e($m['name']) ?>?</b><div class="sub">They lose access for good. Past meetings, minutes and comments keep their name.</div></div>
              <?php if ((int)$m['open_items']): ?><label class="f">Move their <?= plural((int)$m['open_items'], 'open task') ?> to
                <select name="reassign_to" required><option value="">Choose a member…</option><?php foreach ($takers as $t): if ((int)$t['id'] === (int)$m['id']) continue; ?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === uid() ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
              <div class="row"><button class="btn sm primary" type="submit" data-confirm="Permanently remove <?= e($m['name']) ?> from the team?">Delete member</button></div>
            </form></details><?php endif; ?>
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
