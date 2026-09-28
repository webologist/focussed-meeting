<div>
  <h1>Reset your password</h1>
  <p class="muted" style="margin:4px 0 0">Enter your account email and we’ll send you a reset link.</p>
</div>
<form method="post" action="<?= e(path('forgot-password')) ?>">
  <?= csrf_field() ?>
  <label class="f">Email<input type="email" name="email" id="email" required autocomplete="email" autofocus></label>
  <button class="btn primary" type="submit">Send reset link</button>
</form>
<p class="muted small" style="margin:0"><a href="<?= e(path('login')) ?>">Back to sign in</a></p>
