-- Choir Connect -- schema for MyDataWorld
-- Run this in phpMyAdmin's SQL tab against the MyDataWorld database, AFTER
-- My Apps Hub's own api/schema.sql (this relies on the shared users/sessions/
-- apps/app_access tables, plus app_usage_log, already existing).
--
-- Table names are prefixed cc_ so they can't collide with another app's
-- tables in this database (choir_* belongs to the SoJo member app; this is a
-- separate, multi-choir app).

-- ---------- PLATFORM TABLES (shared across every MyDataWorld app) ----------
-- Already exist from My Apps Hub / T-Minus; idempotent so this file also
-- works standalone on a fresh database.

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NULL,
  display_name   VARCHAR(100) NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- CHOIR CONNECT TABLES ----------

-- One row per choir this deployment serves. folder_slug is the path segment
-- under SONG_FILES_BASE_URL that holds this choir's Songs/ folder.
CREATE TABLE IF NOT EXISTS cc_choirs (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  choir_number  INT NOT NULL UNIQUE,
  name          VARCHAR(150) NOT NULL,
  folder_slug   VARCHAR(100) NOT NULL DEFAULT '',
  idx           INT NOT NULL DEFAULT 0
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A "program" is a concert / season / set list. The member app shows the
-- newest program first -- that's ORDER BY sequence DESC (higher = newer).
CREATE TABLE IF NOT EXISTS cc_programs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  choir_number    INT NOT NULL,
  program_number  INT NOT NULL,
  name            VARCHAR(200) NOT NULL,
  sequence        INT NOT NULL DEFAULT 0,
  idx             INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_choir_program (choir_number, program_number)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per track (a single audio file). Tracks are grouped by song_number
-- in the UI; song_folder + filename locate the real file under
-- SONG_FILES_BASE_URL/{choir folder_slug}/Songs/{song_folder}/{filename}.
CREATE TABLE IF NOT EXISTS cc_rehearsal_tracks (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  choir_number       INT NOT NULL,
  program_number     INT NOT NULL,
  song_number        INT NOT NULL,
  song_title         VARCHAR(200) NULL,
  song_folder        VARCHAR(120) NOT NULL DEFAULT '',
  track_sequence     INT NOT NULL DEFAULT 0,
  short_description   VARCHAR(255) NULL,
  track_name         VARCHAR(200) NOT NULL DEFAULT '',
  filename           VARCHAR(255) NOT NULL DEFAULT '',
  idx                INT NOT NULL DEFAULT 0,
  KEY ix_tracks_lookup (choir_number, program_number, song_number, track_sequence)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which apps-hub user (by email == users.username) may see which choir.
-- This is the second access layer: a My Apps Hub app_access grant for
-- 'choir-connect' lets someone open the app at all; a row here scopes them
-- to a specific choir. Maintained from admin.html (the "ChoirUsers" table).
CREATE TABLE IF NOT EXISTS cc_choir_users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  choir_number  INT NOT NULL,
  user_email    VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_choir_user (choir_number, user_email)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SEED: first choir ----------
-- The first choir this app is being built for is The DeciBelles. Its files
-- live under SONG_FILES_BASE_URL/DeciBelles/Songs/... so folder_slug = 'DeciBelles'.

INSERT INTO cc_choirs (choir_number, name, folder_slug, idx) VALUES
  (1, 'The DeciBelles', 'DeciBelles', 10)
ON DUPLICATE KEY UPDATE name = VALUES(name), folder_slug = VALUES(folder_slug), idx = VALUES(idx);

-- A sample program so programs.html isn't empty before real data is entered.
INSERT INTO cc_programs (choir_number, program_number, name, sequence, idx) VALUES
  (1, 1, 'Sample Program', 10, 10)
ON DUPLICATE KEY UPDATE name = VALUES(name), sequence = VALUES(sequence), idx = VALUES(idx);

-- ---------- SCHEMA CHANGE: Volunteer sign-ups ----------
-- Run once. Admin posts volunteer needs per choir (a task, an optional date,
-- a slot count); members claim a slot and can cancel their own signup. A
-- signup is tied to a member by the email they log in with (users.username),
-- so releaseSlot can find and remove only their own row.

CREATE TABLE IF NOT EXISTS cc_volunteer_tasks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  choir_number  INT NOT NULL,
  entry_date    DATE NULL,
  task_name     VARCHAR(255) NOT NULL,
  slots_needed  INT NOT NULL DEFAULT 0,
  idx           INT NOT NULL DEFAULT 0,
  KEY ix_vtasks_choir (choir_number)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cc_volunteer_signups (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  choir_number    INT NOT NULL,
  entry_date      DATE NULL,
  task_name       VARCHAR(255) NOT NULL,
  volunteer_name  VARCHAR(255) NULL,
  phone_number    VARCHAR(30)  NULL,
  email           VARCHAR(255) NULL,
  signed_up_at    DATETIME NULL,
  KEY ix_vsignups (choir_number, task_name, entry_date)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SCHEMA CHANGE: Schedule ----------
-- Run once. Per-choir rehearsal / event schedule. time_text is free text
-- ("7:00 PM", "Call 6:30"); the member page sorts by date descending and
-- shows past events too, so same-day ordering is by idx. location becomes a
-- Google Maps link on the member page (map_url wins if the admin pasted one,
-- otherwise a maps search on the location text).

CREATE TABLE IF NOT EXISTS cc_schedule (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  choir_number  INT NOT NULL,
  event_date    DATE NULL,
  time_text     VARCHAR(60) NULL,
  title         VARCHAR(200) NULL,
  location      VARCHAR(255) NULL,
  map_url       VARCHAR(500) NULL,
  instructions  TEXT NULL,
  notes         TEXT NULL,
  idx           INT NOT NULL DEFAULT 0,
  KEY ix_schedule_choir (choir_number, event_date)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BOOTSTRAP (run once, after you've signed up through My Apps Hub):
--
-- 1) Register the two apps with the Hub -- see the block appended to
--    my-apps-hub/api/schema.sql (app_key 'choir-connect' and
--    'choir-connect-admin'). Run that in phpMyAdmin too.
--
-- 2) Grant yourself both apps (or do it from the Hub's admin.html):
--      INSERT INTO app_access (user_id, app_id)
--      SELECT u.id, a.id FROM users u, apps a
--      WHERE u.username = 'you@example.com'
--        AND a.app_key IN ('choir-connect', 'choir-connect-admin');
--
-- 3) Put yourself in choir 1 so the app has something to show:
--      INSERT INTO cc_choir_users (choir_number, user_email)
--      VALUES (1, 'you@example.com');
-- ============================================================
