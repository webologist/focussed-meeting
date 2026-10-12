# Architecture

## Request flow

`public/index.php` → `app/bootstrap.php` (autoload, config) → session → `app/routes.php` → `Core/Router` (CSRF check on every POST, middleware: `guest`, `auth`, `auth-unverified`, `admin`) → controller → view rendered inside a layout.

## Code map

| Folder | What lives there |
|---|---|
| `app/Core` | Router, DB (PDO wrapper), Auth, Session, Csrf, Crypto (AES-256-GCM), View, Icons |
| `app/Services` | Access (tenant scoping and role rules), Actions, Meetings, Notifier (outbox, email, WhatsApp, in-app), SmtpMailer, Ics, GoogleCalendar, MicrosoftGraph, WhatsApp, Integrations, Audit |
| `app/Controllers` | One per area: Auth, Dashboard, Meeting, Action, Reminder, Notification, Report, Account, Team, Integration, Public |
| `app/Views` | Plain PHP templates. Every value is printed through `e()` |
| `cron/run.php` | Outbox retries, due reminders, deadline reminders, overdue alerts, daily digests, housekeeping |

## Data model (MySQL)

- `organizations`: the tenant. Every business row carries `org_id`.
- `users`: one company per email address; `role` admin|participant; `status` active|invited|disabled.
- `projects`: optional grouping for meetings and tasks (`project_id` on `meetings` and `actions`; action items inherit their meeting's project).
- `meetings` (`sent_personal` and `personal_note` for personal invites), `meeting_invitees` (attendance, RSVP), `agenda_items` (details, presenter, time, sub-points JSON, minutes discussed). Points an attendee asks to add carry `request_status` pending|approved|declined with the host's `review_note`; only points with no request status or an approved one are on the agenda (`Meetings::ON_AGENDA`).
- `actions`: meeting action items and standalone tasks (`meeting_id` NULL). `status` open|progress|review|done: *review* means the owner marked it complete and it waits for the approver, who is the meeting's host for action items and the person who assigned it otherwise (`Access::approverId`). `action_dependencies`, `action_notes` + `note_images`, `action_comments` (with system history).
- `oauth_clients`: a company's own Google / Microsoft OAuth app (secret encrypted), used instead of the platform app in `config.php`.
- `reminders`, `notifications`, `outbox` (every email/WhatsApp message, retried by cron), `integrations` (encrypted per-company config), `auth_tokens`, `login_attempts`, `audit_log`.

## Platform owner

A separate `platform_admins` login at `/owner` (not a company user). The owner sees all companies and users, changes plan and seat limit, suspends or restores a company (its members can't sign in while suspended), changes roles, turns user access off or on, and creates one-time password links. Owner actions are recorded in `platform_audit`. Owner sessions end after 2 hours idle. Create or recover the owner with `php database/owner.php EMAIL`.

## Roles

| | Admin | Participant |
|---|---|---|
| Plan meetings (with a project) | ✓ | ✓ (invite existing teammates only) |
| Manage a meeting, cancel, approve its agenda requests | ✓ | Only as host (organiser) |
| Print meeting documents | Only as host | Only as host |
| See meetings | All in company | Invited or organised |
| Write minutes | ✓ | ✓ (invited) |
| Ask to add a discussion point | – | ✓ (invited) |
| Assign action items and tasks to others | ✓ | ✗ (own to-dos only) |
| Reassign an item to a teammate | ✓ | Items they own or assigned, or as host |
| Mark complete / move status / move deadline | ✓ | Items they own or created |
| Approve completed items | ✓ | As host of the meeting, or who assigned the task |
| Notes and images | Owner of the item only | Owner of the item only |
| Send reminders to others | ✓ | Items they created |
| Reports, Integrations, roles, invites | ✓ | ✗ |

## Security

- Passwords: `password_hash` (bcrypt/argon per PHP default), rehash on login; 8+ characters with letters and numbers.
- Sessions: HttpOnly, SameSite=Lax, Secure on HTTPS, regenerated on login, 8-hour idle timeout.
- CSRF token on every POST; SQL only through prepared statements; output escaped with `e()`.
- Login and password-reset throttling (8 attempts per 15 minutes per email or IP).
- Email verification, reset and invite links are single-use and only their SHA-256 hash is stored.
- Company secrets (SMTP passwords, OAuth refresh tokens, WhatsApp tokens) are encrypted with AES-256-GCM using `app.key`.
- Uploads: images only (checked by content), 5 MB each, random names, stored outside the web root and served only after an access check.
- Tenant isolation: every query is scoped by `org_id` through `Access`; OAuth callbacks use a one-time `state`.
- CSV export escapes cells that start with `=`, `+`, `-` or `@`.

## Roadmap ideas

Billing (Razorpay subscriptions using `organizations.plan` and `seats_limit`), two-way calendar sync, meeting templates and recurring meetings, AI summaries of minutes, a REST API and mobile push notifications.
