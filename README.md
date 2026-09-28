# Focused Meetings

A multi-company SaaS for running meetings that end with owners, deadlines and follow-through.
Plain **PHP 8 + MySQL**, no framework and no Composer, so it runs on ordinary shared hosting (Hostinger, cPanel) as well as a VPS.

## What it does

**For every company (tenant)**
- Compulsory registration. A company signs up, the first user becomes **Admin**, and invites the rest of the team.
- Roles: **Admin** (plans meetings, assigns tasks, sends reminders to others, reports, integrations, roles) and **Participant** (own meetings and tasks, minutes, notes and images, personal reminders). The organiser of a meeting manages that meeting.
- Each company connects **its own** email (Gmail, Outlook, Zoho, Hostinger or any SMTP), **Google Meet**, **Microsoft Teams** and, optionally, **WhatsApp Business**. Credentials are encrypted at rest (AES-256-GCM).

**Meetings**
- Meetings list and planner on one page. Agenda points have a title, details, presenter, time and sub-points, with a time budget against the meeting length.
- Invites go out from the company’s own email with an `.ics` calendar file, or as Google Calendar / Outlook invites with a Meet or Teams link created automatically. WhatsApp invites are optional.
- RSVP, print-ready invite, cancel with calendar cancellation.
- After the meeting: attendance, minutes per point, points raised in the meeting, action items (priority, responsible person, deadline).
- Minutes of Meeting: on-screen, print / save as PDF, copy as text, and email to all attendees.

**Follow-up**
- My dashboard: to-do list, assign tasks (admins), reminders first, upcoming meetings, minutes still to finish.
- Action tracker: filters, new deadline with automatic history, comments, bulk reminders for overdue items.
- Item page: dependencies ("waits on", with loop protection), assignee notes with images, comments and history.
- Reminders: personal "remind me", reminders sent to the person responsible, deadline reminders, overdue alerts, daily digest (email, WhatsApp, in-app).
- Reports: completion rate, on-time rate, overdue, deadline slips, per person and per meeting, CSV export.

## Requirements

- PHP 8.1 or newer with `pdo_mysql`, `openssl`, `curl`, `mbstring`, `fileinfo`
- MySQL 8.0+ or MariaDB 10.5+
- Apache with `mod_rewrite` (or Nginx, see docs/DEPLOY.md)
- A cron job every 5 minutes

## Quick start (local)

```bash
cp config/config.sample.php config/config.php     # then edit database, app.url and app.key
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"  # paste into app.key
mysql -u root -e "CREATE DATABASE focused_meetings CHARACTER SET utf8mb4"
php database/install.php
php -S localhost:8000 -t public server.php
```

Open http://localhost:8000/register and create your company.

Background jobs (reminders, alerts, digests, retries):

```bash
php cron/run.php
```

## Documentation

- [docs/DEPLOY.md](docs/DEPLOY.md): step-by-step deployment on Hostinger / cPanel and on a VPS
- [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md): Gmail / SMTP, Google Meet, Microsoft Teams and WhatsApp setup
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): code structure, data model, roles and security

## Project layout

```
app/            Controllers, Services, Views, Core (router, DB, auth, CSRF, crypto)
config/         config.sample.php (copy to config.php; never commit it)
cron/run.php    background jobs, run every 5 minutes
database/       schema.sql and install.php
public/         web root: index.php, assets
storage/        uploads, logs, sessions (not web-accessible)
```
