<div>
  <div class="eyebrow"><?= icon('shield', 13) ?> Platform owner</div>
  <h1>Owner console</h1>
  <p class="muted" style="margin:4px 0 0">Manage every company on <?= e(config('app.name')) ?>.</p>
</div>
<form method="post" action="<?= e(path('owner/login')) ?>">
  <?= csrf_field() ?>
  <label class="f">Email<input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="username" autofocus></label>
  <label class="f">Password<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn primary" type="submit">Sign in</button>
</form>
<p class="muted small" style="margin:0">Forgot the owner password? Run the owner setup again on the server to get a new link.</p>
