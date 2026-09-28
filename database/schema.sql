-- Focused Meetings database schema
-- MySQL 8.0+ or MariaDB 10.5+. Import into an empty database:
--   mysql -u USER -p DBNAME < database/schema.sql
-- All timestamps are stored in UTC.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------------------------------------------------------------
-- Companies (tenants). Every business table carries org_id.
-- ---------------------------------------------------------------
CREATE TABLE organizations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  slug          VARCHAR(160) NOT NULL UNIQUE,
  timezone      VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',
  plan          VARCHAR(20)  NOT NULL DEFAULT 'free',     -- free | pro | business (billing in a later phase)
  seats_limit   INT UNSIGNED NULL,                        -- NULL = unlimited
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id             INT UNSIGNED NOT NULL,
  name               VARCHAR(120) NOT NULL,
  email              VARCHAR(190) NOT NULL UNIQUE,
  password_hash      VARCHAR(255) NULL,                   -- NULL until an invited user accepts
  phone              VARCHAR(30)  NULL,
  title              VARCHAR(120) NULL,
  role               ENUM('admin','participant') NOT NULL DEFAULT 'participant',
  status             ENUM('active','invited','disabled') NOT NULL DEFAULT 'active',
  email_verified_at  DATETIME NULL,
  last_login_at      DATETIME NULL,
  invited_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NULL,
  KEY idx_users_org (org_id, status),
  CONSTRAINT fk_users_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_settings (
  user_id           INT UNSIGNED PRIMARY KEY,
  timezone          VARCHAR(64) NULL,
  signature         TEXT NULL,
  default_platform  ENUM('meet','teams','inperson') NOT NULL DEFAULT 'meet',
  default_duration  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  invite_email      TINYINT(1) NOT NULL DEFAULT 1,
  invite_whatsapp   TINYINT(1) NOT NULL DEFAULT 0,
  reminder_days     TINYINT UNSIGNED NOT NULL DEFAULT 1,   -- remind my assignees N days before deadline (0 = off)
  digest            TINYINT(1) NOT NULL DEFAULT 1,
  digest_time       CHAR(5) NOT NULL DEFAULT '09:00',
  overdue_alerts    TINYINT(1) NOT NULL DEFAULT 1,
  wa_reminders      TINYINT(1) NOT NULL DEFAULT 0,
  last_digest_on    DATE NULL,
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email verification, password reset and team invitation tokens (only the SHA-256 hash is stored)
CREATE TABLE auth_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  type        ENUM('verify','reset','invite') NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_tokens_user (user_id, type),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(190) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_attempts (email, created_at),
  KEY idx_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Integrations: one row per company per provider. Secrets are AES-256-GCM encrypted with app.key.
-- provider: smtp (incl. Gmail app password), google (Meet via Calendar API), microsoft (Teams via Graph), whatsapp (Cloud API)
-- ---------------------------------------------------------------
CREATE TABLE integrations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id        INT UNSIGNED NOT NULL,
  provider      ENUM('smtp','google','microsoft','whatsapp') NOT NULL,
  account_label VARCHAR(190) NULL,               -- e.g. connected email address shown in the UI
  config_enc    TEXT NOT NULL,
  status        ENUM('connected','error') NOT NULL DEFAULT 'connected',
  last_error    VARCHAR(500) NULL,
  connected_by  INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NULL,
  UNIQUE KEY uq_integration (org_id, provider),
  CONSTRAINT fk_integrations_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Meetings
-- ---------------------------------------------------------------
CREATE TABLE meetings (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id             INT UNSIGNED NOT NULL,
  organizer_id       INT UNSIGNED NOT NULL,
  title              VARCHAR(200) NOT NULL,
  meeting_date       DATE NOT NULL,              -- local date in the meeting timezone
  start_time         CHAR(5) NOT NULL,           -- HH:MM local
  duration_min       SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  timezone           VARCHAR(64) NOT NULL,
  starts_at_utc      DATETIME NOT NULL,
  platform           ENUM('meet','teams','inperson') NOT NULL DEFAULT 'meet',
  location           VARCHAR(255) NULL,
  join_url           VARCHAR(500) NULL,
  external_event_id  VARCHAR(255) NULL,          -- Google/Microsoft calendar event id
  status             ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  sent_email         TINYINT(1) NOT NULL DEFAULT 1,
  sent_whatsapp      TINYINT(1) NOT NULL DEFAULT 0,
  invite_sent_at     DATETIME NULL,
  mom_sent_at        DATETIME NULL,
  completed_at       DATETIME NULL,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NULL,
  KEY idx_meetings_org_date (org_id, meeting_date),
  CONSTRAINT fk_meetings_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_meetings_organizer FOREIGN KEY (organizer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invitees must be registered members of the company (registration is compulsory)
CREATE TABLE meeting_invitees (
  meeting_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  attended    TINYINT(1) NOT NULL DEFAULT 1,
  rsvp        ENUM('pending','yes','no','maybe') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (meeting_id, user_id),
  KEY idx_invitee_user (user_id),
  CONSTRAINT fk_inv_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agenda_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id      INT UNSIGNED NOT NULL,
  position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  title           VARCHAR(255) NOT NULL,
  details         TEXT NULL,
  presenter_id    INT UNSIGNED NULL,
  minutes_allotted SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  subpoints       JSON NULL,                     -- array of strings
  discussion      TEXT NULL,                     -- minutes discussed
  added_in_meeting TINYINT(1) NOT NULL DEFAULT 0,
  updated_by      INT UNSIGNED NULL,
  updated_at      DATETIME NULL,
  KEY idx_agenda_meeting (meeting_id, position),
  CONSTRAINT fk_agenda_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_agenda_presenter FOREIGN KEY (presenter_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Action items and tasks (a task is an action with no meeting)
-- ---------------------------------------------------------------
CREATE TABLE actions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id          INT UNSIGNED NOT NULL,
  meeting_id      INT UNSIGNED NULL,
  agenda_item_id  INT UNSIGNED NULL,
  title           VARCHAR(255) NOT NULL,
  details         TEXT NULL,
  priority        ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  owner_id        INT UNSIGNED NOT NULL,
  created_by      INT UNSIGNED NOT NULL,
  deadline        DATE NOT NULL,
  new_deadline    DATE NULL,
  status          ENUM('open','done') NOT NULL DEFAULT 'open',
  done_at         DATETIME NULL,
  last_due_reminder_on DATE NULL,
  last_overdue_alert_on DATE NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NULL,
  KEY idx_actions_org_status (org_id, status),
  KEY idx_actions_owner (owner_id, status),
  KEY idx_actions_meeting (meeting_id),
  CONSTRAINT fk_actions_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_actions_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_actions_agenda FOREIGN KEY (agenda_item_id) REFERENCES agenda_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_actions_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_actions_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE action_dependencies (
  action_id      INT UNSIGNED NOT NULL,
  depends_on_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (action_id, depends_on_id),
  CONSTRAINT fk_dep_action FOREIGN KEY (action_id) REFERENCES actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_dep_on FOREIGN KEY (depends_on_id) REFERENCES actions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes and images: only the person responsible can add them
CREATE TABLE action_notes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  body        TEXT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_notes_action (action_id),
  CONSTRAINT fk_notes_action FOREIGN KEY (action_id) REFERENCES actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE note_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id     INT UNSIGNED NOT NULL,
  org_id      INT UNSIGNED NOT NULL,
  stored_name VARCHAR(80) NOT NULL,             -- random file name inside storage/uploads/{org_id}/
  mime        VARCHAR(40) NOT NULL,
  size_bytes  INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  CONSTRAINT fk_images_note FOREIGN KEY (note_id) REFERENCES action_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comments and history (is_system = automatic entries like "Deadline moved")
CREATE TABLE action_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  body        TEXT NOT NULL,
  is_system   TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  KEY idx_comments_action (action_id),
  CONSTRAINT fk_comments_action FOREIGN KEY (action_id) REFERENCES actions(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal reminders ("remind me")
CREATE TABLE reminders (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id       INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  action_id    INT UNSIGNED NULL,
  body         VARCHAR(255) NOT NULL,
  remind_at    DATETIME NOT NULL,               -- UTC
  via_email    TINYINT(1) NOT NULL DEFAULT 1,
  via_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
  fired_at     DATETIME NULL,
  created_at   DATETIME NOT NULL,
  KEY idx_reminders_due (fired_at, remind_at),
  KEY idx_reminders_user (user_id, fired_at),
  CONSTRAINT fk_reminders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reminders_action FOREIGN KEY (action_id) REFERENCES actions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app notifications (bell)
CREATE TABLE notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  body        VARCHAR(500) NOT NULL,
  link        VARCHAR(255) NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_notif_user (user_id, read_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outgoing messages. Sent immediately when possible; the cron job retries failures.
CREATE TABLE outbox (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id       INT UNSIGNED NULL,              -- NULL = platform/system email
  channel      ENUM('email','whatsapp') NOT NULL DEFAULT 'email',
  to_address   VARCHAR(190) NOT NULL,
  to_name      VARCHAR(120) NULL,
  subject      VARCHAR(255) NULL,
  body_html    MEDIUMTEXT NULL,
  body_text    TEXT NULL,
  attachments  MEDIUMTEXT NULL,               -- JSON: [{name, mime, content_base64}]
  status       ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error   VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL,
  sent_at      DATETIME NULL,
  KEY idx_outbox_status (status, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id      INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  event       VARCHAR(60) NOT NULL,
  entity      VARCHAR(40) NULL,
  entity_id   INT UNSIGNED NULL,
  meta        VARCHAR(1000) NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_audit_org (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
