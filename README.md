# MeetFlow

A multi-company SaaS for running meetings that end with owners, deadlines and follow-through.
Plain **PHP 8 + MySQL**, no framework and no Composer, so it runs on ordinary shared hosting (Hostinger, cPanel) as well as a VPS.

## What it does

**For every company (tenant)**
- Compulsory registration. A company signs up, the first user becomes **Admin**, and invites the rest of the team.
- Roles: **Admin** (assigns tasks to anyone, invites people, sends reminders to others, reports, integrations, roles) and **Participant** (plans meetings, own tasks, minutes, notes and images, personal reminders). The organiser of a meeting is its **host**: manages it, prints its documents and approves its completed action items.
- Each company connects **its own** email (Gmail, Outlook, Zoho, Hostinger or any SMTP), **Google Meet**, **Microsoft Teams** and, optionally, **WhatsApp Business**. **Quick connect** signs in with Google or Microsoft once (OAuth consent) and covers meetings and email together; a company can register its own OAuth app from the Integrations page. Credentials are encrypted at rest (AES-256-GCM).
- **Projects** group meetings and tasks; anyone can start one, and meetings, the task list and the planner filter by project.

**Meetings**
- Meetings list and planner on one page. Agenda points have a title, details, presenter, time and sub-points, with a time budget against the meeting length.
- Invites go out from the company’s own email with an `.ics` calendar file, or as Google Calendar / Outlook invites with a Meet or Teams link created automatically. WhatsApp invites are optional. A **personal invite** option prints a signed invitation letter per invitee for the host to hand out, and an optional personal message goes with every invite.
- Attendees can **ask to add a discussion point**; requests wait under **By Admin’s permission** until the host approves or declines them with a message.
- RSVP, cancel with calendar cancellation.
- After the meeting: attendance, minutes per point (saving asks for confirmation), points raised in the meeting, action items (priority, responsible person, deadline).
- Minutes of Meeting open with a formal attendance section (who was invited, designation, role, present or absent). On screen, copy as text, and email to all attendees.
- **Printing is for the host only**: invitation, personal invitation letters, attendance register with signatures, minutes of meeting, and action items.

**Follow-up**
- My dashboard: to-do list, items to approve, all my tasks, assign tasks (admins), reminders first, upcoming meetings, meetings needing attention.
- Task status: To do → In progress → Completed (awaiting approval) → Approved. The owner confirms before marking complete and can move the item back until the host approves; the host approves or sends it back with a note.
- Hand over: the owner (or whoever assigned it) can reassign an item to another team member with a handover note.
- All tasks: every task, sortable by assignee, status, priority or deadline, with filters by person, priority and project; new deadline with automatic history, comments, bulk reminders for overdue items.
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

Platform owner (super admin) console at `/owner`:

```bash
php database/owner.php you@example.com   # prints a one-time link to set the owner password
```

On Windows, `local\start-local.bat` runs everything (its own MySQL on port 3310, the app on http://localhost:8080) and `local\owner-setup.bat` creates the owner.

Demo data for local development (two sample companies with meetings, action items and comments):

```bash
php database/seed.php           # --fresh reloads it; on Windows double-click local\seed-demo.bat
```

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
