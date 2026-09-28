<?php
use App\Controllers\IntegrationController;
$logo = [
  'email' => '<div class="logo" style="background:var(--accent-soft);color:var(--accent)">' . icon('mail', 20) . '</div>',
  'meet' => '<div class="logo" style="background:#E6F4EA"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="13" height="12" rx="2" fill="#00AC47"/><path d="M15 10l6-4v12l-6-4z" fill="#FFBA00"/><rect x="2" y="6" width="5" height="5" fill="#2684FC"/></svg></div>',
  'teams' => '<div class="logo" style="background:#ECEBFA"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="13" height="14" rx="2" fill="#5059C9"/><path d="M6.5 9h6M9.5 9v7" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="19" cy="7" r="2.3" fill="#7B83EB"/><rect x="17" y="10" width="5" height="7" rx="2" fill="#7B83EB"/></svg></div>',
  'wa' => '<div class="logo" style="background:#E3F7EA"><svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3z" fill="#25D366"/></svg></div>',
];
$pill = fn(?array $i) => $i ? ($i['status'] === 'error' ? '<span class="pill p-high">Needs attention</span>' : '<span class="pill s-done">Connected</span>') : '<span class="pill s-neutral">Not connected</span>';
$preset = $smtp['preset'] ?? 'gmail';
$discon = fn(string $p) => '<form method="post" action="' . e(path('integrations/' . $p . '/disconnect')) . '" class="inline">' . csrf_field() . '<button class="btn sm ghost" type="submit" data-confirm="Disconnect this integration for everyone in your company?">Disconnect</button></form>';
?>
<div class="head"><div><div class="eyebrow">Settings</div><h1>Integrations</h1><p class="muted">Connect your company’s own email and meeting tools. Everything is sent from your accounts, not ours.</p></div></div>

<div class="panel" id="email">
  <div class="panel-h"><div class="icard-h"><?= $logo['email'] ?><div><h2>Email (Gmail, Outlook or any SMTP)</h2><?= $pill($ints['smtp'] ?? null) ?></div></div>
    <?php if (isset($ints['smtp'])): ?><div class="row"><span class="faint small">Sending as <?= e($ints['smtp']['account_label']) ?></span>
      <form method="post" action="<?= e(path('integrations/smtp/test')) ?>" class="inline"><?= csrf_field() ?><button class="btn sm" type="submit">Send test</button></form><?= $discon('smtp') ?></div><?php endif; ?></div>
  <div class="panel-b stack">
    <?php if (!empty($ints['smtp']['last_error'])): ?><div class="flash error">Last error: <?= e($ints['smtp']['last_error']) ?></div><?php endif; ?>
    <?php if (!isset($ints['smtp'])): ?><p class="muted small" style="margin:0"><?= config('mail.fallback_for_companies') ? 'Until you connect one, invites go out from the ' . e(config('app.name')) . ' mail server.' : 'Connect an email account so invites, reminders and minutes can be sent.' ?></p><?php endif; ?>
    <form method="post" action="<?= e(path('integrations/smtp')) ?>" class="stack" id="smtp-form"><?= csrf_field() ?>
      <div class="fields">
        <label class="f">Provider<select name="preset" id="smtp-preset" data-presets="<?= e(json_encode(IntegrationController::SMTP_PRESETS)) ?>">
          <?php foreach (IntegrationController::SMTP_PRESETS as $k => $p): ?><option value="<?= $k ?>" <?= $preset === $k ? 'selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?></select></label>
        <label class="f">SMTP host<input type="text" name="host" id="smtp-host" value="<?= e($smtp['host'] ?? IntegrationController::SMTP_PRESETS[$preset]['host']) ?>" required></label>
        <label class="f">Port<input type="number" name="port" id="smtp-port" value="<?= (int)($smtp['port'] ?? IntegrationController::SMTP_PRESETS[$preset]['port']) ?>" required></label>
        <label class="f">Security<select name="encryption" id="smtp-enc"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= ($smtp['encryption'] ?? IntegrationController::SMTP_PRESETS[$preset]['encryption']) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="fields">
        <label class="f">Username (usually your email)<input type="text" name="username" value="<?= e($smtp['username'] ?? '') ?>" autocomplete="off" required></label>
        <label class="f">Password or app password<input type="password" name="password" autocomplete="new-password" placeholder="<?= isset($smtp['password']) ? 'Saved. Leave blank to keep it' : '' ?>" <?= isset($smtp['password']) ? '' : 'required' ?>></label>
        <label class="f">Send from email<input type="email" name="from_email" value="<?= e($smtp['from_email'] ?? user()['email']) ?>" required></label>
        <label class="f">Sender name<input type="text" name="from_name" value="<?= e($smtp['from_name'] ?? user()['org_name']) ?>"></label>
      </div>
      <p class="hint" id="smtp-help" style="margin:0"><?= e(IntegrationController::SMTP_PRESETS[$preset]['help']) ?></p>
      <div><button class="btn primary" type="submit"><?= isset($ints['smtp']) ? 'Save and send test' : 'Connect and send test' ?></button></div>
    </form>
  </div>
</div>

<div class="icards">
  <div class="icard" id="google">
    <div class="icard-h"><?= $logo['meet'] ?><div><h3>Google Meet</h3><?= $pill($ints['google'] ?? null) ?></div></div>
    <p>Creates a Meet link and a Google Calendar invite for every invitee, from your company’s Google account.</p>
    <?php if (!empty($ints['google']['last_error'])): ?><div class="flash error"><?= e($ints['google']['last_error']) ?></div><?php endif; ?>
    <div class="foot"><?php if (isset($ints['google'])): ?><span class="faint small"><?= e($ints['google']['account_label']) ?></span><div class="row"><a class="btn sm" href="<?= e(path('integrations/google/connect')) ?>">Reconnect</a><?= $discon('google') ?></div>
      <?php elseif ($googleReady): ?><a class="btn primary" href="<?= e(path('integrations/google/connect')) ?>">Connect Google account</a>
      <?php else: ?><span class="faint small">The server owner needs to add Google OAuth keys first (docs/INTEGRATIONS.md).</span><?php endif; ?></div>
  </div>
  <div class="icard" id="microsoft">
    <div class="icard-h"><?= $logo['teams'] ?><div><h3>Microsoft Teams</h3><?= $pill($ints['microsoft'] ?? null) ?></div></div>
    <p>Creates a Teams meeting and an Outlook invite for every invitee, from your company’s Microsoft 365 account.</p>
    <?php if (!empty($ints['microsoft']['last_error'])): ?><div class="flash error"><?= e($ints['microsoft']['last_error']) ?></div><?php endif; ?>
    <div class="foot"><?php if (isset($ints['microsoft'])): ?><span class="faint small"><?= e($ints['microsoft']['account_label']) ?></span><div class="row"><a class="btn sm" href="<?= e(path('integrations/microsoft/connect')) ?>">Reconnect</a><?= $discon('microsoft') ?></div>
      <?php elseif ($msReady): ?><a class="btn primary" href="<?= e(path('integrations/microsoft/connect')) ?>">Connect Microsoft 365</a>
      <?php else: ?><span class="faint small">The server owner needs to add Microsoft app keys first (docs/INTEGRATIONS.md).</span><?php endif; ?></div>
  </div>
</div>

<div class="panel" id="whatsapp">
  <div class="panel-h"><div class="icard-h"><?= $logo['wa'] ?><div><h2>WhatsApp Business (optional)</h2><?= $pill($ints['whatsapp'] ?? null) ?></div></div><?php if (isset($ints['whatsapp'])) echo $discon('whatsapp'); ?></div>
  <div class="panel-b stack">
    <p class="muted small" style="margin:0">Sends invites, reminders and minutes links through the WhatsApp Business Cloud API. Get these values from Meta Business Suite → WhatsApp → API setup. Messages to people who haven’t written to you in 24 hours need an approved template with one text variable.</p>
    <form method="post" action="<?= e(path('integrations/whatsapp')) ?>" class="stack"><?= csrf_field() ?>
      <div class="fields">
        <label class="f">Phone number ID<input type="text" name="phone_number_id" value="<?= e($wa['phone_number_id'] ?? '') ?>" required></label>
        <label class="f">Display number<input type="text" name="display_number" value="<?= e($wa['display_number'] ?? '') ?>" placeholder="+91 98xxx xxxxx"></label>
        <label class="f">Permanent access token<input type="password" name="access_token" autocomplete="off" placeholder="<?= isset($wa['access_token']) ? 'Saved. Leave blank to keep it' : '' ?>" <?= isset($wa['access_token']) ? '' : 'required' ?>></label>
      </div>
      <div class="fields">
        <label class="f">Template name <span class="hint">optional</span><input type="text" name="template" value="<?= e($wa['template'] ?? '') ?>" placeholder="e.g. meeting_update"></label>
        <label class="f">Template language<input type="text" name="language" value="<?= e($wa['language'] ?? 'en') ?>"></label>
      </div>
      <div><button class="btn primary" type="submit"><?= isset($ints['whatsapp']) ? 'Save' : 'Connect WhatsApp' ?></button></div>
    </form>
  </div>
</div>
