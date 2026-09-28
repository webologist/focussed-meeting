# Integrations

Every company connects its own accounts in **Integrations** (admins only). Invites, reminders and minutes are then sent from the company’s own mailbox and calendar. Secrets are stored encrypted with `app.key`.

Google Meet and Microsoft Teams use OAuth, so **you, the platform owner, register one app** with Google and one with Microsoft and put the keys in `config/config.php`. Each company then clicks **Connect** and signs in with its own account. Email and WhatsApp need no platform setup.

## Email: Gmail, Outlook, Zoho, Hostinger or any SMTP

In Integrations → Email, pick the provider, enter the mailbox and password, and click **Connect and send test**. The connection is saved only if the test email goes through.

| Provider | Host | Port / security | Notes |
|---|---|---|---|
| Gmail / Google Workspace | smtp.gmail.com | 587 STARTTLS | Needs 2-step verification and an **app password** (myaccount.google.com/apppasswords). The normal password won’t work. |
| Outlook / Microsoft 365 | smtp.office365.com | 587 STARTTLS | The Microsoft 365 admin must enable **Authenticated SMTP** for the mailbox. |
| Zoho Mail | smtp.zoho.in (India) / smtp.zoho.com | 465 SSL | Use an app-specific password if 2FA is on. |
| Hostinger | smtp.hostinger.com | 465 SSL | Full mailbox address and its password. |

Until a company connects email, its messages go through the platform mailer in `config.php` (set `mail.fallback_for_companies` to `false` to turn that off).

## Google Meet (Google Calendar API)

1. Go to https://console.cloud.google.com, create a project, and enable the **Google Calendar API**.
2. **OAuth consent screen:** User type *External*, add your app name, support email and domain, and add the scopes `openid`, `email` and `https://www.googleapis.com/auth/calendar.events`.
3. **Credentials → Create OAuth client ID → Web application.** Authorised redirect URI:
   `https://YOUR-DOMAIN/integrations/google/callback`
4. Put the Client ID and Client secret into `config.php` under `google`.
5. While the consent screen is in *Testing*, only test users you add can connect. Submit it for **verification** before selling to other companies (the calendar scope is "sensitive", so Google reviews it; allow a few weeks).

When a company plans a Google Meet meeting, the app creates the event in the connected account’s calendar with a Meet link, and Google emails calendar invites to everyone.

## Microsoft Teams (Microsoft Graph)

1. Go to https://entra.microsoft.com → App registrations → **New registration**.
   - Supported account types: **Accounts in any organizational directory (multi-tenant)**
   - Redirect URI (Web): `https://YOUR-DOMAIN/integrations/microsoft/callback`
2. **Certificates & secrets → New client secret.** Copy the value.
3. **API permissions → Microsoft Graph → Delegated:** `User.Read`, `Calendars.ReadWrite`, `OnlineMeetings.ReadWrite`, `offline_access`, `openid`, `email`.
4. Put the Application (client) ID and secret into `config.php` under `microsoft` (keep `tenant` as `common`).
5. Some customer tenants require their admin to approve the app the first time. Their admin can do that from the consent prompt.

Teams meetings need a work or school Microsoft 365 account with Teams. Personal Outlook.com accounts can’t create Teams meetings through Graph.

## WhatsApp Business (optional)

1. In https://business.facebook.com set up WhatsApp and add a phone number (Meta → WhatsApp → API setup).
2. Create a **System user** with a **permanent access token** that has `whatsapp_business_messaging`.
3. For messages to people who haven’t messaged you in the last 24 hours, create and get approval for a **Utility template** with one body variable, for example `{{1}}`, and enter its name in Integrations.
4. In Integrations → WhatsApp enter the Phone number ID, token and template name.

People receive WhatsApp messages only if their profile has a mobile number in international format.
