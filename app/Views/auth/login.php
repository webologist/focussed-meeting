<div>
  <h1>Sign in</h1>
  <p class="muted" style="margin:4px 0 0">Welcome back. Pick up where your meetings left off.</p>
</div>
<form method="post" action="<?= e(path('login')) ?>">
  <?= csrf_field() ?>
  <label class="f">Email<input type="email" name="email" id="email" value="<?= e(old('email')) ?>" required autocomplete="email" autofocus></label>
  <label class="f">Password<input type="password" name="password" id="password" required autocomplete="current-password"></label>
  <div class="row" style="justify-content:flex-end"><a class="small" href="<?= e(path('forgot-password')) ?>">Forgot password?</a></div>
  <button class="btn primary" type="submit">Sign in</button>
</form>
<?php if (config('app.allow_signups')): ?><p class="muted small" style="margin:0">New here? <a href="<?= e(path('register')) ?>">Create a company workspace</a></p><?php endif; ?>
