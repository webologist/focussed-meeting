<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Audit;
use App\Services\GoogleCalendar;
use App\Services\Integrations;
use App\Services\MicrosoftGraph;
use App\Services\Notifier;
use App\Services\SmtpMailer;

final class IntegrationController
{
    public const SMTP_PRESETS = [
        'gmail'     => ['label' => 'Gmail / Google Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'help' => 'Turn on 2-step verification, then create an app password at myaccount.google.com/apppasswords and use it here.'],
        'outlook'   => ['label' => 'Outlook / Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls', 'help' => 'Your Microsoft 365 admin must allow SMTP AUTH for this mailbox. Use an app password if MFA is on.'],
        'zoho'      => ['label' => 'Zoho Mail', 'host' => 'smtp.zoho.in', 'port' => 465, 'encryption' => 'ssl', 'help' => 'Use smtp.zoho.com outside India. Generate an app-specific password if 2FA is on.'],
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
            'googleReady' => GoogleCalendar::configured(), 'msReady' => MicrosoftGraph::configured(), 'title' => 'Integrations',
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

    public function testSmtp(): void
    {
        $int = Integrations::get(org_id(), 'smtp');
        if (!$int) { flash('error', 'Connect an email account first.'); redirect('integrations#email'); }
        try {
            (new SmtpMailer($int['config']))->send(['to' => user()['email'], 'to_name' => user()['name'], 'subject' => 'Test email from ' . config('app.name'), 'html' => Notifier::layout('Test email', '<p>Your email connection works.</p>')]);
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
        Integrations::remove(org_id(), $provider);
        Audit::log('integration.disconnected', 'integration', null, ['provider' => $provider]);
        flash('success', 'Disconnected.');
        redirect('integrations');
    }

    public function googleConnect(): void
    {
        if (!GoogleCalendar::configured()) { flash('error', 'Google sign-in isn’t set up on this server yet. See docs/INTEGRATIONS.md.'); redirect('integrations'); }
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        redirect(GoogleCalendar::authUrl($_SESSION['oauth_state']));
    }

    public function googleCallback(): void
    {
        $this->finishOAuth('google', fn($code) => GoogleCalendar::exchange($code), 'Google Meet');
    }

    public function microsoftConnect(): void
    {
        if (!MicrosoftGraph::configured()) { flash('error', 'Microsoft sign-in isn’t set up on this server yet. See docs/INTEGRATIONS.md.'); redirect('integrations'); }
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        redirect(MicrosoftGraph::authUrl($_SESSION['oauth_state']));
    }

    public function microsoftCallback(): void
    {
        $this->finishOAuth('microsoft', fn($code) => MicrosoftGraph::exchange($code), 'Microsoft Teams');
    }

    private function finishOAuth(string $provider, callable $exchange, string $label): void
    {
        $state = (string)($_GET['state'] ?? '');
        $expected = (string)($_SESSION['oauth_state'] ?? '');
        unset($_SESSION['oauth_state']);
        if ($expected === '' || !hash_equals($expected, $state)) { flash('error', 'That sign-in attempt expired. Try connecting again.'); redirect('integrations'); }
        if (!empty($_GET['error'])) { flash('error', $label . ' wasn’t connected: ' . ($_GET['error_description'] ?? $_GET['error'])); redirect('integrations'); }
        try {
            [$cfg, $email] = $exchange((string)($_GET['code'] ?? ''));
            Integrations::save(org_id(), $provider, $cfg, $email ?: null, uid());
            Audit::log('integration.connected', 'integration', null, ['provider' => $provider]);
            flash('success', $label . ' connected' . ($email ? ' as ' . $email : '') . '.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('integrations');
    }
}
