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
$brevoMail = ($smtp['preset'] ?? '') === 'brevo' && isset($ints['smtp']);
$oauthMail = $smtp['transport'] ?? null; // 'google' | 'microsoft' when email goes through a signed-in account
$providerName = ['google' => 'Google', 'microsoft' => 'Microsoft 365'];
$discon = fn(string $p) => '<form method="post" action="' . e(path('integrations/' . $p . '/disconnect')) . '" class="inline">' . csrf_field() . '<button class="btn sm ghost" type="submit" data-confirm="Disconnect this integration for everyone in your company?">Disconnect</button></form>';
?>
<div class="head"><div><div class="eyebrow">Settings</div><h1>Integrations</h1><p class="muted">Connect your company’s own email and meeting tools. Everything is sent from your accounts, not ours.</p></div></div>

<div class="panel quick-connect">
  <div class="panel-h"><h2>Quick connect</h2><span class="faint small">Sign in once and approve access. No passwords to copy.</span></div>
  <div class="panel-b qc-grid">
    <div class="qc">
      <?= $logo['meet'] ?><div><b>Google Workspace or Gmail</b><div class="faint small">Google Meet links, Calendar invites and email from your Google account</div></div>
      <?php if (isset($ints['google'])): ?><span class="pill s-done">Connected</span>
      <?php elseif ($googleReady): ?><a class="btn primary" href="<?= e(path('integrations/google/connect', ['mail' => 1])) ?>">Sign in with Google</a>
      <?php else: ?><a class="btn" href="#google">Set up (5 min)</a><?php endif; ?>
    </div>
    <div class="qc">
      <?= $logo['teams'] ?><div><b>Microsoft 365</b><div class="faint small">Teams meetings, Outlook invites and email from your work account</div></div>
      <?php if (isset($ints['microsoft'])): ?><span class="pill s-done">Connected</span>
      <?php elseif ($msReady): ?><a class="btn primary" href="<?= e(path('integrations/microsoft/connect', ['mail' => 1])) ?>">Sign in with Microsoft</a>
      <?php else: ?><a class="btn" href="#microsoft">Set up (5 min)</a><?php endif; ?>
    </div>
    <div class="qc">
      <?= $logo['email'] ?><div><b>Brevo</b><div class="faint small">Send invites, reminders and minutes from your own Brevo account</div></div>
      <?php if ($brevoMail): ?><span class="pill s-done">Connected</span>
      <?php else: ?><a class="btn primary" href="#brevo">Connect Brevo</a><?php endif; ?>
    </div>
  </div>
</div>

<div class="panel" id="brevo">
  <div class="panel-h"><div class="icard-h"><?= $logo['email'] ?><div><h2>Brevo email</h2><?= $brevoMail ? '<span class="pill s-done">Connected</span>' : '<span class="pill s-neutral">Not connected</span>' ?></div></div></div>
  <div class="panel-b stack">
    <p class="muted small" style="margin:0">Messages go out from your own Brevo account, so you control the sender, sending limits and delivery reports. Takes about 2 minutes.</p>
    <ol class="small muted" style="margin:0;padding-left:18px">
      <li>In Brevo open <b>SMTP &amp; API</b> &rarr; <b>SMTP</b>. Copy your <b>SMTP login</b> and click <b>Generate a new SMTP key</b>.</li>
      <li>Under <b>Senders, domains &amp; dedicated IPs</b>, add and verify the address you want to send from.</li>
      <li>Paste them below. We send a test email to you before saving.</li>
    </ol>
    <form method="post" action="<?= e(path('integrations/brevo')) ?>" class="stack"><?= csrf_field() ?>
      <div class="grid2">
        <label class="f">SMTP login<input type="text" name="username" value="<?= $brevoMail ? e($smtp['username'] ?? '') : '' ?>" autocomplete="off" required></label>
        <label class="f">SMTP key<input type="password" name="password" autocomplete="new-password" placeholder="<?= $brevoMail ? 'Saved. Leave blank to keep it' : '' ?>" <?= $brevoMail ? '' : 'required' ?>></label>
        <label class="f">Send from email<input type="email" name="from_email" value="<?= e($brevoMail ? ($smtp['from_email'] ?? '') : '') ?>" placeholder="verified in Brevo" required></label>
        <label class="f">Sender name<input type="text" name="from_name" value="<?= e($brevoMail ? ($smtp['from_name'] ?? '') : user()['org_name']) ?>"></label>
      </div>
      <div><button class="btn primary" type="submit"><?= $brevoMail ? 'Save and send test' : 'Connect and send test' ?></button></div>
    </form>
    <p class="hint" style="margin:0">The key is stored encrypted. Use the SMTP key, not your Brevo account password or an API key.</p>
  </div>
</div>

<div class="panel" id="email">
  <div class="panel-h"><div class="icard-h"><?= $logo['email'] ?><div><h2>Email (Gmail, Outlook, Brevo or any SMTP)</h2><?= $pill($ints['smtp'] ?? null) ?></div></div>
    <?php if (isset($ints['smtp'])): ?><div class="row"><span class="faint small">Sending as <?= e($ints['smtp']['account_label']) ?><?= $oauthMail ? ' through ' . e($providerName[$oauthMail]) : '' ?></span>
      <form method="post" action="<?= e(path('integrations/smtp/test')) ?>" class="inline"><?= csrf_field() ?><button class="btn sm" type="submit">Send test</button></form><?= $discon('smtp') ?></div><?php endif; ?></div>
  <div class="panel-b stack">
    <?php if (!empty($ints['smtp']['last_error'])): ?><div class="flash error">Last error: <?= e($ints['smtp']['last_error']) ?></div><?php endif; ?>
    <?php if (!isset($ints['smtp'])): ?><p class="muted small" style="margin:0"><?= config('mail.fallback_for_companies') ? 'Until you connect one, invites go out from the ' . e(config('app.name')) . ' mail server.' : 'Connect an email account so invites, reminders and minutes can be sent.' ?></p><?php endif; ?>
    <?php if ($oauthMail): ?><div class="banner"><div>Email is sent from <b><?= e($ints['smtp']['account_label']) ?></b> using your <?= e($providerName[$oauthMail]) ?> sign-in. Nothing else to set up. To use a different mailbox, fill in the SMTP details below instead.</div></div>
    <?php elseif (!isset($ints['smtp']) && ($googleReady || $msReady)): ?><div class="banner"><div>Easiest: use <b>Quick connect</b> above and allow sending email. Or enter SMTP details below.</div></div><?php endif; ?>
    <details <?= $oauthMail ? '' : 'open' ?>><summary class="linkish small"><?= $oauthMail ? 'Use SMTP instead' : 'SMTP settings' ?></summary>
    <form method="post" action="<?= e(path('integrations/smtp')) ?>" class="stack" id="smtp-form" style="margin-top:12px"><?= csrf_field() ?>
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
      <div><button class="btn primary" type="submit"><?= isset($ints['smtp']) && !$oauthMail ? 'Save and send test' : 'Connect and send test' ?></button></div>
    </form></details>
  </div>
</div>

<div class="icards">
<?php foreach ([
    ['google', 'meet', 'Google Meet', 'Creates a Meet link and a Google Calendar invite for every invitee, from your company’s Google account.', $googleReady, $googleApp, $platformGoogle, 'Google'],
    ['microsoft', 'teams', 'Microsoft Teams', 'Creates a Teams meeting and an Outlook invite for every invitee, from your company’s Microsoft 365 account.', $msReady, $msApp, $platformMs, 'Microsoft 365'],
] as [$prov, $logoKey, $name, $blurb, $ready, $ownApp, $platformApp, $brand]): $int = $ints[$prov] ?? null; ?>
  <div class="icard" id="<?= $prov ?>">
    <div class="icard-h"><?= $logo[$logoKey] ?><div><h3><?= e($name) ?></h3><?= $pill($int) ?></div></div>
    <p><?= e($blurb) ?></p>
    <?php if (!empty($int['last_error'])): ?><div class="flash error"><?= e($int['last_error']) ?></div><?php endif; ?>
    <div class="foot">
      <?php if ($int): ?><span class="faint small"><?= e($int['account_label']) ?><?= $oauthMail === $prov ? ' · also sends email' : '' ?></span>
        <div class="row"><a class="btn sm" href="<?= e(path('integrations/' . $prov . '/connect', ['mail' => $oauthMail === $prov || !isset($ints['smtp']) ? 1 : null])) ?>">Reconnect</a><?= $discon($prov) ?></div>
      <?php elseif ($ready): ?>
        <form method="get" action="<?= e(path('integrations/' . $prov . '/connect')) ?>" class="stack" style="gap:8px;width:100%">
          <label class="check"><input type="checkbox" name="mail" value="1" checked>Also send invites, reminders and minutes from this account</label>
          <div><button class="btn primary" type="submit">Connect <?= e($brand) ?></button></div></form>
      <?php else: ?><span class="faint small">Add your <?= e($brand) ?> app below (one time, about 5 minutes), then connect.</span><?php endif; ?>
    </div>
    <details class="setup" <?= !$ready ? 'open' : '' ?>><summary class="linkish small"><?= $ownApp ? 'Your own ' . e($brand) . ' app' : ($platformApp ? 'Use your own ' . e($brand) . ' app instead' : 'Step 1: add your ' . e($brand) . ' app') ?></summary>
      <div class="stack" style="gap:10px;margin-top:10px">
        <?php if ($prov === 'google'): ?>
        <ol class="steps-list">
          <li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud → Credentials</a> (create a project if asked) and enable the <b>Google Calendar API</b> and <b>Gmail API</b>.</li>
          <li>Set up the <b>OAuth consent screen</b>: choose <i>Internal</i> for a Workspace domain (no review needed), or <i>External</i> and add yourself as a test user.</li>
          <li><b>Create credentials → OAuth client ID → Web application</b>, and add this authorised redirect URI:</li>
        </ol>
        <?php else: ?>
        <ol class="steps-list">
          <li>Open <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Microsoft Entra → App registrations</a> and click <b>New registration</b>.</li>
          <li>Pick <i>Accounts in this organizational directory only</i> and add a <b>Web</b> redirect URI (below). Then <b>Certificates &amp; secrets → New client secret</b>.</li>
          <li>Under <b>API permissions</b> add Microsoft Graph delegated permissions <code>Calendars.ReadWrite</code>, <code>OnlineMeetings.ReadWrite</code>, <code>Mail.Send</code>, <code>User.Read</code> and <code>offline_access</code>, then <b>Grant admin consent</b>.</li>
        </ol>
        <?php endif; ?>
        <div class="copy-row"><input type="text" readonly value="<?= e(url('integrations/' . $prov . '/callback')) ?>" aria-label="Redirect URI" id="redir-<?= $prov ?>"><button type="button" class="btn sm" data-copy-value="#redir-<?= $prov ?>">Copy</button></div>
        <form method="post" action="<?= e(path('integrations/' . $prov . '/app')) ?>" class="stack" style="gap:8px"><?= csrf_field() ?>
          <label class="f"><?= $prov === 'google' ? 'Client ID' : 'Application (client) ID' ?><input type="text" name="client_id" value="<?= e($ownApp['client_id'] ?? '') ?>" required autocomplete="off" placeholder="<?= $prov === 'google' ? '1234-abc.apps.googleusercontent.com' : '00000000-0000-0000-0000-000000000000' ?>"></label>
          <label class="f">Client secret<input type="password" name="client_secret" autocomplete="new-password" <?= $ownApp ? 'placeholder="Saved. Leave blank to keep it"' : 'required' ?>></label>
          <?php if ($prov === 'microsoft'): ?><label class="f">Directory (tenant) ID <span class="hint">optional, blank = any work account</span><input type="text" name="tenant" value="<?= e($ownApp['tenant'] ?? '') ?>" autocomplete="off"></label><?php endif; ?>
          <div class="row"><button class="btn sm primary" type="submit"><?= $ownApp ? 'Save app' : 'Save and continue' ?></button>
            <?php if ($ownApp): ?></div></form>
          <form method="post" action="<?= e(path('integrations/' . $prov . '/app/remove')) ?>" class="inline"><?= csrf_field() ?><button class="btn sm ghost" type="submit" data-confirm="Remove your own <?= e($brand) ?> app? Connected accounts may need to reconnect.">Remove my app</button></form>
            <?php else: ?></div></form><?php endif; ?>
        <p class="hint" style="margin:0">The secret is stored encrypted. Only admins of <?= e(user()['org_name']) ?> can see or change this.</p>
      </div>
    </details>
  </div>
<?php endforeach; ?>
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
