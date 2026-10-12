<div>
  <div class="eyebrow"><?= icon('shield', 13) ?> Platform owner</div>
  <h1>Set your owner password</h1>
  <p class="muted" style="margin:4px 0 0">You’ll sign in as <b><?= e($o['email']) ?></b>. This link works once.</p>
</div>
<form method="post" action="<?= e(path('owner/setup')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <input type="email" value="<?= e($o['email']) ?>" autocomplete="username" hidden readonly>
  <label class="f">Your name<input type="text" name="name" value="<?= e($o['name'] === 'Platform owner' ? '' : $o['name']) ?>" maxlength="120" autocomplete="name" placeholder="e.g. Sunil"></label>
  <label class="f">Password<input type="password" name="password" required minlength="10" autocomplete="new-password" autofocus>
    <span class="pw-rules">At least 10 characters, with letters and numbers.</span></label>
  <label class="f">Confirm password<input type="password" name="password_confirm" required minlength="10" autocomplete="new-password"></label>
  <button class="btn primary" type="submit">Save password and sign in</button>
</form>
