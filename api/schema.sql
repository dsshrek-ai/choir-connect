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
