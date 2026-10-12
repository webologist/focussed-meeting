<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit;
use App\Services\GoogleCalendar;
use App\Services\Integrations;
use App\Services\MicrosoftGraph;
use App\Services\OAuthApps;
use App\Services\Notifier;
use App\Services\SmtpMailer;

final class IntegrationController
{
    public const SMTP_PRESETS = [
        'gmail'     => ['label' => 'Gmail / Google Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'help' => 'Turn on 2-step verification, then create an app password at myaccount.google.com/apppasswords and use it here.'],
        'outlook'   => ['label' => 'Outlook / Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls', 'help' => 'Your Microsoft 365 admin must allow SMTP AUTH for this mailbox. Use an app password if MFA is on.'],
        'zoho'      => ['label' => 'Zoho Mail', 'host' => 'smtp.zoho.in', 'port' => 465, 'encryption' => 'ssl', 'help' => 'Use smtp.zoho.com outside India. Generate an app-specific password if 2FA is on.'],
        'brevo'     => ['label' => 'Brevo (Sendinblue)', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls', 'help' => 'In Brevo go to SMTP & API > SMTP. Use the SMTP login shown there as the username and an SMTP key (not your Brevo account password) as the password. The Send-from email must be a verified sender in Brevo.'],
        'hostinger' => ['label' => 'Hostinger email', 'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl', 'help' => 'Use the full mailbox address and its password.'],
        'custom'    => ['label' => 'Other SMTP server', 'host' => '', 'port' => 587, 'encryption' => 'tls', 'help' => 'Ask your email provider for the SMTP host, port and security type.'],
    ];

    public function index(): void
    {
        $ints = Integrations::all(org_id());
        $smtp = Integrations::get(org_id(), 'smtp');
        $wa = Integrations::get(org_id(), 'whatsapp');
        echo view('integrations/index', [
            'ints' => $ints, 'smtp' => $smtp['config'] ?? [], 'wa' => $wa['config'] ?? [],
            'googleReady' => GoogleCalendar::configured(org_id()), 'msReady' => MicrosoftGraph::configured(org_id()),
            'googleApp' => OAuthApps::companyApp(org_id(), 'google'), 'msApp' => OAuthApps::companyApp(org_id(), 'microsoft'),
            'platformGoogle' => (string)config('google.client_id') !== '', 'platformMs' => (string)config('microsoft.client_id') !== '',
            'title' => 'Integrations',
        ]);
    }

    public function saveSmtp(): void
    {
        $existing = Integrations::get(org_id(), 'smtp')['config'] ?? [];
        $preset = array_key_exists((string)input('preset'), self::SMTP_PRESETS) ? input('preset') : 'custom';
        $cfg = [
            'preset'     => $preset,
            'host'       => (string)input('host'),
            'port'       => (int)input('port', 587),
            'encryption' => in_array(input('encryption'), ['tls', 'ssl', 'none'], true) ? input('encryption') : 'tls',
            'username'   => (string)input('username'),
            'password'   => ($_POST['password'] ?? '') !== '' ? (string)$_POST['password'] : ($existing['password'] ?? ''),
            'from_email' => mb_strtolower((string)input('from_email')),
            'from_name'  => (string)input('from_name') ?: user()['org_name'],
        ];
        if ($cfg['host'] === '' || !$cfg['port']) { flash('error', 'Enter the SMTP host and port.'); redirect('integrations#email'); }
        if (!filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter the email address messages are sent from.'); redirect('integrations#email'); }
        try {
            (new SmtpMailer($cfg))->send(['to' => user()['email'], 'to_name' => user()['name'], 'subject' => 'Test email from ' . config('app.name'),
                'html' => Notifier::layout('Your email is connected', '<p>Invites, reminders and minutes for ' . e(user()['org_name']) . ' will now be sent from <b>' . e($cfg['from_email']) . '</b>.</p>', null, null, user()['org_name'])]);
        } catch (\Throwable $e) {
            flash('error', 'We couldn’t send through that account: ' . $e->getMessage());
            redirect('integrations#email');
        }
        Integrations::save(org_id(), 'smtp', $cfg, $cfg['from_email'], uid());
        Audit::log('integration.connected', 'integration', null, ['provider' => 'smtp']);
        flash('success', 'Email connected. We sent a test message to ' . user()['email'] . '.');
        redirect('integrations#email');
    }

    /** Each company admin connects their own Brevo account: SMTP login + SMTP key + a sender verified in Brevo. */
    public function saveBrevo(): void
    {
        $existing = Integrations::get(org_id(), 'smtp')['config'] ?? [];
        $keep = ($existing['preset'] ?? '') === 'brevo' ? ($existing['password'] ?? '') : '';
        $cfg = [
            'preset'     => 'brevo',
            'host'       => 'smtp-relay.brevo.com',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => trim((string)input('username')),
            'password'   => trim((string)($_POST['password'] ?? '')) !== '' ? trim((string)$_POST['password']) : $keep,
            'from_email' => mb_strtolower(trim((string)input('from_email'))),
            'from_name'  => trim((string)input('from_name')) ?: user()['org_name'],
        ];
        if ($cfg['username'] === '' || $cfg['password'] === '') { flash('error', 'Paste your Brevo SMTP login and SMTP key.'); redirect('integrations#brevo'); }
        if (!filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) { flash('error', 'Enter a sender email that is verified in Brevo.'); redirect('integrations#brevo'); }
        try {
            (new SmtpMailer($cfg))->send(['to' => user()['email'], 'to_name' => user()['name'], 'subject' => 'Test email from ' . config('app.name'),
                'html' => Notifier::layout('Brevo is connected', '<p>Invites, reminders and minutes for ' . e(user()['org_name']) . ' will now be sent through your Brevo account from <b>' . e($cfg['from_email']) . '</b>.</p>', null, null, user()['org_name'])]);
        } catch (\Throwable $e) {
            flash('error', 'Brevo rejected that: ' . $e->getMessage() . ' Check the SMTP login and key, and that the sender is verified in Brevo.');
            redirect('integrations#brevo');
        }
        Integrations::save(org_id(), 'smtp', $cfg, $cfg['from_email'] . ' (Brevo)', uid());
        Audit::log('integration.connected', 'integration', null, ['provider' => 'smtp', 'via' => 'brevo']);
        flash('success', 'Brevo connected. We sent a test message to ' . user()['email'] . '.');
        redirect('integrations#email');
    }

    public function testSmtp(): void
    {
        $int = Integrations::get(org_id(), 'smtp');
        if (!$int) { flash('error', 'Connect an email account first.'); redirect('integrations#email'); }
        try {
            Notifier::sendMail(org_id(), $int['config'], ['to' => user()['email'], 'to_name' => user()['name'], 'subject' => 'Test email from ' . config('app.name'), 'html' => Notifier::layout('Test email', '<p>Your email connection works.</p>')]);
            flash('success', 'Test email sent to ' . user()['email'] . '.');
        } catch (\Throwable $e) {
            Integrations::markError(org_id(), 'smtp', $e->getMessage());
            flash('error', 'Test failed: ' . $e->getMessage());
        }
        redirect('integrations#email');
    }

    public function saveWhatsApp(): void
    {
        $existing = Integrations::get(org_id(), 'whatsapp')['config'] ?? [];
        $cfg = [
            'phone_number_id' => preg_replace('/\D/', '', (string)input('phone_number_id')),
            'display_number'  => (string)input('display_number'),
            'access_token'    => ($_POST['access_token'] ?? '') !== '' ? trim((string)$_POST['access_token']) : ($existing['access_token'] ?? ''),
            'template'        => preg_replace('/[^a-z0-9_]/', '', mb_strtolower((string)input('template'))),
            'language'        => preg_replace('/[^a-zA-Z_]/', '', (string)input('language', 'en')) ?: 'en',
        ];
        if (!$cfg['phone_number_id'] || !$cfg['access_token']) { flash('error', 'Enter the Phone number ID and access token from Meta.'); redirect('integrations#whatsapp'); }
        Integrations::save(org_id(), 'whatsapp', $cfg, $cfg['display_number'] ?: $cfg['phone_number_id'], uid());
        Audit::log('integration.connected', 'integration', null, ['provider' => 'whatsapp']);
        flash('success', 'WhatsApp connected.');
        redirect('integrations#whatsapp');
    }

    public function disconnect(string $provider): void
    {
        if (!in_array($provider, ['smtp', 'google', 'microsoft', 'whatsapp'], true)) abort(404);
        // Email sent through this Google / Microsoft account stops working with it
        if (in_array($provider, ['google', 'microsoft'], true) && (Integrations::get(org_id(), 'smtp')['config']['transport'] ?? '') === $provider) Integrations::remove(org_id(), 'smtp');
        Integrations::remove(org_id(), $provider);
        Audit::log('integration.disconnected', 'integration', null, ['provider' => $provider]);
        flash('success', 'Disconnected.');
        redirect('integrations');
    }

    /** A company registers its own Google / Microsoft OAuth app, so no server setup is needed. */
    public function saveApp(string $provider): void
    {
        if (!in_array($provider, ['google', 'microsoft'], true)) abort(404);
        $clientId = trim((string)input('client_id'));
        $secret = trim((string)($_POST['client_secret'] ?? ''));
        $existing = OAuthApps::get(org_id(), $provider);
        if ($secret === '' && ($existing['source'] ?? '') === 'company') $secret = $existing['client_secret'];
        if ($clientId === '' || $secret === '') { flash('error', 'Paste both the client ID and the client secret.'); redirect('integrations#' . $provider); }
        if ($provider === 'google' && !str_ends_with($clientId, '.apps.googleusercontent.com')) { flash('error', 'That doesn’t look like a Google client ID. It ends in .apps.googleusercontent.com.'); redirect('integrations#google'); }
        $tenant = $provider === 'microsoft' ? (preg_replace('/[^A-Za-z0-9.\-]/', '', (string)input('tenant')) ?: null) : null;
        OAuthApps::save(org_id(), $provider, mb_substr($clientId, 0, 250), $secret, $tenant, uid());
        Audit::log('integration.app_saved', 'integration', null, ['provider' => $provider]);
        flash('success', 'App saved. Now click Connect and approve access on the consent screen.');
        redirect('integrations#' . $provider);
    }

    public function removeApp(string $provider): void
    {
        if (!in_array($provider, ['google', 'microsoft'], true)) abort(404);
        OAuthApps::remove(org_id(), $provider);
        Audit::log('integration.app_removed', 'integration', null, ['provider' => $provider]);
        flash('success', 'Your own app was removed.' . (OAuthApps::get(org_id(), $provider) ? ' The platform app will be used from now on. Reconnect if sign-in stops working.' : ''));
        redirect('integrations#' . $provider);
    }

    public function googleConnect(): void
    {
        if (!GoogleCalendar::configured(org_id())) { flash('error', 'Add your Google OAuth app first (step 1 below).'); redirect('integrations#google'); }
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        $_SESSION['oauth_mail'] = !empty($_GET['mail']);
        redirect(GoogleCalendar::authUrl($_SESSION['oauth_state'], org_id(), $_SESSION['oauth_mail']));
    }

    public function googleCallback(): void
    {
        $this->finishOAuth('google', fn($code) => GoogleCalendar::exchange($code, org_id()), 'Google');
    }

    public function microsoftConnect(): void
    {
        if (!MicrosoftGraph::configured(org_id())) { flash('error', 'Add your Microsoft app first (step 1 below).'); redirect('integrations#microsoft'); }
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        $_SESSION['oauth_mail'] = !empty($_GET['mail']);
        redirect(MicrosoftGraph::authUrl($_SESSION['oauth_state'], org_id(), $_SESSION['oauth_mail']));
    }

    public function microsoftCallback(): void
    {
        $this->finishOAuth('microsoft', fn($code) => MicrosoftGraph::exchange($code, org_id()), 'Microsoft 365');
    }

    private function finishOAuth(string $provider, callable $exchange, string $label): void
    {
        $state = (string)($_GET['state'] ?? '');
        $expected = (string)($_SESSION['oauth_state'] ?? '');
        $wantMail = !empty($_SESSION['oauth_mail']);
        unset($_SESSION['oauth_state'], $_SESSION['oauth_mail']);
        if ($expected === '' || !hash_equals($expected, $state)) { flash('error', 'That sign-in attempt expired. Try connecting again.'); redirect('integrations'); }
        if (!empty($_GET['error'])) { flash('error', $label . ' wasn’t connected: ' . ($_GET['error_description'] ?? $_GET['error'])); redirect('integrations'); }
        try {
            [$cfg, $email] = $exchange((string)($_GET['code'] ?? ''));
            Integrations::save(org_id(), $provider, $cfg, $email ?: null, uid());
            Audit::log('integration.connected', 'integration', null, ['provider' => $provider]);
            $what = $provider === 'google' ? 'Google Meet' : 'Microsoft Teams';
            if ($wantMail && !empty($cfg['mail']) && $email) {
                // Send invites, reminders and minutes from this account too: no SMTP password needed
                Integrations::save(org_id(), 'smtp', ['transport' => $provider, 'from_email' => $email, 'from_name' => user()['org_name']], $email, uid());
                Audit::log('integration.connected', 'integration', null, ['provider' => 'smtp', 'via' => $provider]);
                $what .= ' and email';
            } elseif ($wantMail) {
                flash('error', 'Email permission wasn’t granted, so email still goes out the way it did before. Connect again and allow sending email to use this account for email.');
            }
            flash('success', $label . ' connected' . ($email ? ' as ' . $email : '') . '. ' . $what . ' now run from this account.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('integrations');
    }
}
