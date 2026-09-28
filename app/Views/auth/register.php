<div>
  <div class="eyebrow">Free to start</div>
  <h1>Create your company workspace</h1>
  <p class="muted" style="margin:4px 0 0">You’ll be the admin. Invite your team once you’re in.</p>
</div>
<form method="post" action="<?= e(path('register')) ?>" novalidate>
  <?= csrf_field() ?>
  <label class="f">Company or team name
    <input type="text" name="company" id="company" value="<?= e(old('company')) ?>" required autocomplete="organization" placeholder="e.g. Vertical Infinity">
    <?php if (!empty($errors['company'])): ?><span class="field-error"><?= e($errors['company']) ?></span><?php endif; ?></label>
  <label class="f">Your full name
    <input type="text" name="name" id="name" value="<?= e(old('name')) ?>" required autocomplete="name">
    <?php if (!empty($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?></label>
  <label class="f">Work email
    <input type="email" name="email" id="email" value="<?= e(old('email')) ?>" required autocomplete="email">
    <?php if (!empty($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?></label>
  <label class="f">Mobile / WhatsApp <span class="hint">optional</span>
    <input type="tel" name="phone" id="phone" value="<?= e(old('phone')) ?>" autocomplete="tel" placeholder="+91 98xxx xxxxx"></label>
  <label class="f">Password
    <input type="password" name="password" id="password" required autocomplete="new-password" minlength="8">
    <span class="pw-rules">At least 8 characters, with letters and numbers.</span>
    <?php if (!empty($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?></label>
  <label class="check" style="font-size:.84rem"><input type="checkbox" name="terms" id="terms" value="1"> I agree to the terms of service and privacy policy</label>
  <?php if (!empty($errors['terms'])): ?><span class="field-error"><?= e($errors['terms']) ?></span><?php endif; ?>
  <button class="btn primary" type="submit">Create workspace</button>
</form>
<p class="muted small" style="margin:0">Already have an account? <a href="<?= e(path('login')) ?>">Sign in</a></p>
