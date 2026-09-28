<div>
  <div class="eyebrow">Invitation</div>
  <h1>Join <?= e($invitee['org_name']) ?></h1>
  <p class="muted" style="margin:4px 0 0">Set up your account for <b><?= e($invitee['email']) ?></b>.</p>
</div>
<form method="post" action="<?= e(path('accept-invite')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <label class="f">Full name<input type="text" name="name" id="name" value="<?= e($invitee['name']) ?>" required autocomplete="name"></label>
  <label class="f">Mobile / WhatsApp <span class="hint">optional</span><input type="tel" name="phone" id="phone" value="<?= e($invitee['phone'] ?? '') ?>" autocomplete="tel"></label>
  <label class="f">Password<input type="password" name="password" id="password" required autocomplete="new-password" minlength="8">
    <span class="pw-rules">At least 8 characters, with letters and numbers.</span></label>
  <button class="btn primary" type="submit">Create my account</button>
</form>
