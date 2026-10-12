<div class="head"><div><div class="eyebrow">Platform owner</div><h1>Owner account</h1><p class="muted">Signed in as <?= e($o['email']) ?>. Owner sessions end after 2 hours without activity.</p></div></div>

<div class="grid-even">
  <form class="panel" method="post" action="<?= e(path('owner/account/password')) ?>"><?= csrf_field() ?>
    <div class="panel-h"><h2>Change password</h2><button class="btn sm primary" type="submit">Save</button></div>
    <div class="panel-b fields">
      <input type="email" value="<?= e($o['email']) ?>" autocomplete="username" hidden readonly>
      <label class="f">Current password<input type="password" name="current" required autocomplete="current-password"></label>
      <label class="f">New password<input type="password" name="password" required minlength="10" autocomplete="new-password"><span class="pw-rules">At least 10 characters, with letters and numbers.</span></label>
      <label class="f">Confirm new password<input type="password" name="password_confirm" required minlength="10" autocomplete="new-password"></label>
    </div></form>

  <div class="panel"><div class="panel-h"><h2>Account details</h2></div>
    <div class="panel-b">
      <dl class="kv-list">
        <dt>Email</dt><dd><?= e($o['email']) ?></dd>
        <dt>Last sign-in (UTC)</dt><dd class="mono"><?= e($o['last_login_at'] ? substr($o['last_login_at'], 0, 16) : '—') ?></dd>
        <?php foreach ($log as $l): ?><dt class="mono small"><?= e(substr($l['created_at'], 0, 16)) ?></dt><dd class="small"><?= e(str_replace('owner.', '', $l['event'])) ?> · <?= e($l['ip']) ?></dd><?php endforeach; ?>
      </dl>
    </div></div>
</div>
