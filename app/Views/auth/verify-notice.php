<div class="stack">
  <div style="color:var(--accent)"><?= icon('mail', 36) ?></div>
  <h1>Check your inbox</h1>
  <p class="muted" style="margin:0">We sent a confirmation link to <b><?= e(user()['email']) ?></b>. Open it to finish setting up your account.</p>
  <p class="muted small" style="margin:0">Didn’t get it? Check spam, or send a new link.</p>
  <div class="row">
    <form method="post" action="<?= e(path('verify-email/resend')) ?>" class="inline"><?= csrf_field() ?><button class="btn primary" type="submit">Send a new link</button></form>
    <form method="post" action="<?= e(path('logout')) ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit">Use a different email</button></form>
  </div>
</div>
