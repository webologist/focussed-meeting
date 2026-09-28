<?php
use App\Controllers\AccountController;
$u = user();
$sw = fn(string $name, $on) => '<label class="switch"><input type="checkbox" name="' . $name . '" value="1" ' . ($on ? 'checked' : '') . '><i></i></label>';
?>
<div class="head"><div><div class="eyebrow">Settings</div><h1>My account</h1><p class="muted">Your profile, meeting defaults, reminders and password.</p></div></div>
<div class="acct">
  <div class="stack">
    <div class="panel acct-card"><?= avatar($u, 72) ?><h2><?= e($u['name']) ?></h2><span class="muted small"><?= e($u['title'] ?: '') ?><?= $u['title'] ? ' · ' : '' ?><?= e($u['org_name']) ?></span>
      <span class="pill <?= $u['role'] === 'admin' ? 'p-admin' : 's-neutral' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Participant' ?></span>
      <a class="small" href="<?= e(path('team')) ?>">See your team</a></div>
    <div class="panel"><div class="panel-b stack" style="gap:10px">
      <div class="row" style="justify-content:space-between"><span class="muted small">Meetings organised</span><b class="mono"><?= $stats['organised'] ?></b></div>
      <div class="row" style="justify-content:space-between"><span class="muted small">Items assigned to you</span><b class="mono"><?= $stats['assigned'] ?></b></div>
      <div class="row" style="justify-content:space-between"><span class="muted small">Completed</span><b class="mono"><?= $stats['done'] ?></b></div>
    </div></div>
  </div>
  <div class="stack">
    <form class="panel" method="post" action="<?= e(path('account/profile')) ?>"><?= csrf_field() ?>
      <div class="panel-h"><h2>Profile</h2><button class="btn sm primary" type="submit">Save profile</button></div>
      <div class="panel-b stack">
        <div class="fields">
          <label class="f">Full name<input type="text" name="name" value="<?= e($u['name']) ?>" required maxlength="120"></label>
          <label class="f">Email<input type="email" name="email" value="<?= e($u['email']) ?>" required></label>
          <label class="f">Mobile / WhatsApp<input type="tel" name="phone" value="<?= e($u['phone']) ?>" placeholder="+91 98xxx xxxxx"></label>
        </div>
        <div class="fields">
          <label class="f">Designation<input type="text" name="title" value="<?= e($u['title']) ?>" maxlength="120"></label>
          <label class="f">Time zone<select name="timezone"><option value="">Company default (<?= e($u['org_timezone']) ?>)</option>
            <?php foreach (AccountController::TIMEZONES as $z): ?><option <?= ($settings['timezone'] ?? '') === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="f">Signature on minutes<textarea name="signature" maxlength="1000" placeholder="e.g. <?= e($u['name']) ?> · <?= e($u['org_name']) ?>"><?= e($settings['signature']) ?></textarea></label>
      </div></form>

    <form class="panel" method="post" action="<?= e(path('account/preferences')) ?>" id="preferences"><?= csrf_field() ?>
      <div class="panel-h"><h2>Meetings &amp; reminders</h2><button class="btn sm primary" type="submit">Save preferences</button></div>
      <div class="panel-b">
        <?php if (is_admin()): ?>
        <div class="pref"><div><b>Default platform</b><span>Used when you plan a new meeting</span></div>
          <select name="default_platform"><?php foreach (['meet', 'teams', 'inperson'] as $p): ?><option value="<?= $p ?>" <?= $settings['default_platform'] === $p ? 'selected' : '' ?>><?= e(platform_label($p)) ?></option><?php endforeach; ?></select></div>
        <div class="pref"><div><b>Default duration</b><span>Length of a new meeting</span></div>
          <select name="default_duration"><?php foreach ([15, 30, 45, 60, 90, 120] as $n): ?><option value="<?= $n ?>" <?= (int)$settings['default_duration'] === $n ? 'selected' : '' ?>><?= $n ?> min</option><?php endforeach; ?></select></div>
        <div class="pref"><div><b>Send invites by email</b><span>Ticked by default in the planner</span></div><?= $sw('invite_email', $settings['invite_email']) ?></div>
        <div class="pref"><div><b>Send invites on WhatsApp</b><span>Needs WhatsApp connected in Integrations</span></div><?= $sw('invite_whatsapp', $settings['invite_whatsapp']) ?></div>
        <div class="pref"><div><b>Remind people before their deadline</b><span>For items you assign</span></div>
          <select name="reminder_days"><?php foreach ([0 => 'Off', 1 => '1 day before', 2 => '2 days before', 3 => '3 days before'] as $v => $l): ?><option value="<?= $v ?>" <?= (int)$settings['reminder_days'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="pref"><div><b>Alert me when items I assigned go overdue</b><span>So you can follow up</span></div><?= $sw('overdue_alerts', $settings['overdue_alerts']) ?></div>
        <?php else: ?>
          <input type="hidden" name="default_platform" value="<?= e($settings['default_platform']) ?>"><input type="hidden" name="default_duration" value="<?= (int)$settings['default_duration'] ?>">
          <input type="hidden" name="reminder_days" value="<?= (int)$settings['reminder_days'] ?>"><?php if ($settings['invite_email']): ?><input type="hidden" name="invite_email" value="1"><?php endif; ?><?php if ($settings['overdue_alerts']): ?><input type="hidden" name="overdue_alerts" value="1"><?php endif; ?>
        <?php endif; ?>
        <div class="pref"><div><b>Daily digest of my pending items</b><span>Email summary each morning</span></div>
          <div class="row" style="flex-wrap:nowrap"><input type="time" name="digest_time" value="<?= e($settings['digest_time']) ?>" style="width:auto" aria-label="Digest time"><?= $sw('digest', $settings['digest']) ?></div></div>
        <div class="pref"><div><b>Reminders on WhatsApp</b><span>In addition to email, when your company has WhatsApp connected</span></div><?= $sw('wa_reminders', $settings['wa_reminders']) ?></div>
      </div></form>

    <form class="panel" method="post" action="<?= e(path('account/password')) ?>" id="security"><?= csrf_field() ?>
      <div class="panel-h"><h2>Password</h2><button class="btn sm primary" type="submit">Change password</button></div>
      <div class="panel-b"><div class="fields">
        <label class="f">Current password<input type="password" name="current" required autocomplete="current-password"></label>
        <label class="f">New password<input type="password" name="password" required minlength="8" autocomplete="new-password"><span class="pw-rules">At least 8 characters, with letters and numbers.</span></label>
      </div></div></form>
  </div>
</div>
