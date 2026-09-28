<div><h1>Choose a new password</h1></div>
<form method="post" action="<?= e(path('reset-password')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <label class="f">New password<input type="password" name="password" id="password" required autocomplete="new-password" minlength="8" autofocus>
    <span class="pw-rules">At least 8 characters, with letters and numbers.</span></label>
  <button class="btn primary" type="submit">Save password and sign in</button>
</form>
