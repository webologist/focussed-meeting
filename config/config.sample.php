<?php
/**
 * Focused Meetings configuration.
 * Copy this file to config/config.php and fill in your values.
 * Never commit config/config.php to version control.
 */
return [
    'app' => [
        'name'      => 'Focused Meetings',
        // Full public URL of the app, no trailing slash. e.g. https://meetings.yourdomain.com
        'url'       => 'http://localhost:8000',
        // 64 hex chars. Generate with: php -r "echo bin2hex(random_bytes(32));"
        // Used to encrypt each company's SMTP passwords and OAuth tokens. Do not change after go-live.
        'key'       => 'CHANGE_ME_64_HEX_CHARS',
        'env'       => 'production',          // 'production' or 'development'
        'timezone'  => 'Asia/Kolkata',        // default for new companies
        // Require users to verify their email before they can use the app.
        'require_email_verification' => true,
        // Allow new companies to sign up from the public registration page.
        'allow_signups' => true,
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'focused_meetings',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // Platform mailer: used for system emails (verify email, password reset, team invites)
    // and as a fallback when a company has not connected its own email yet.
    'mail' => [
        'host'       => 'smtp.hostinger.com',
        'port'       => 465,
        'encryption' => 'ssl',               // 'ssl', 'tls' or 'none'
        'username'   => 'no-reply@yourdomain.com',
        'password'   => '',
        'from_email' => 'no-reply@yourdomain.com',
        'from_name'  => 'Focused Meetings',
        // When a company has no email connected, send its invites/minutes through the platform mailer.
        'fallback_for_companies' => true,
    ],

    // Google Cloud OAuth client (one per platform). Each company connects its own Google account.
    // Redirect URI to register in Google Cloud: {app.url}/integrations/google/callback
    'google' => [
        'client_id'     => '',
        'client_secret' => '',
    ],

    // Microsoft Entra (Azure AD) app registration, multi-tenant. Each company connects its own Microsoft 365 account.
    // Redirect URI to register: {app.url}/integrations/microsoft/callback
    'microsoft' => [
        'client_id'     => '',
        'client_secret' => '',
        'tenant'        => 'common',
    ],

    'uploads' => [
        'max_bytes'     => 5 * 1024 * 1024,   // per image
        'max_per_note'  => 6,
    ],
];
