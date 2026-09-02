# Stack Overview

How Choir Connect is built and how it fits with its sibling apps.

## Front end

- Static HTML/CSS/vanilla JS — no framework, no build step, no bundler.
- A few self-contained pages (`index.html`, `schedule.html`, `programs.html`,
  `program.html`, `song.html`, `volunteer.html`, `admin.html`), each with its own
  inline `<script>`. The rehearsal-tracks flow is programs -> program (song
  titles) -> song (tracks).
- One shared `style.css` and one shared `js/api.js` (fetch wrapper, SSO/login
  helpers, the nav + multi-choir picker bootstrap).
- Hosted on **GitHub Pages** — `git push` to `main` auto-deploys.

## Back end

- A single PHP file, `api/api.php` — an action router (`?action=programs`, POST
  body `{"action":"adminAdd", ...}`), not a framework.
- `mysqli`, prepared statements throughout.
- Hosted on shared cPanel-style hosting (`seniorfamily.org`), deployed by manual
  FTP upload.
- `api/config.php` (gitignored) holds DB credentials plus `SONG_FILES_BASE_URL`.

## Database — "MyDataWorld"

- The shared MySQL database used by My Apps Hub, T-Minus, the SoJo member app, and
  others.
- Shared tables: `users`, `sessions`, `apps`, `app_access`, `app_usage_log` — the
  cross-app login / access / usage-logging system.
- This app's own tables are prefixed `cc_` (`cc_choirs`, `cc_programs`,
  `cc_rehearsal_tracks`, `cc_choir_users`).
- Schema changes are additive SQL run by hand in phpMyAdmin (`api/schema.sql`).

## Auth — two layers

1. **Shared MyDataWorld login** (bearer-token sessions), issued centrally by **My
   Apps Hub**. Launching from the Hub passes `?token=...`, a real row in the
   shared `sessions` table, which this app adopts directly as its own Bearer
   token (no separate login exchange).
2. **Per-app grant** via `app_access` + an `app_key`: `choir-connect` for the
   member pages, `choir-connect-admin` for `admin.html`. Every action checks it.
3. **Per-choir grant** via `cc_choir_users` (`choir_number`, `user_email`): every
   content action also checks that the caller's email is mapped to the choir it
   asked for. Maintained from `admin.html`'s **ChoirUsers** table.

Every successful member action also upserts a row into the shared
`app_usage_log` (`app_key = 'choir-connect'`), best-effort.

## File hosting

- Rehearsal-track audio files are real uploads, FTP'd into
  `SONG_FILES_BASE_URL/<choir FolderSlug>/Songs/<SongFolder>/`. The database
  stores only folder + filename; `api.php` builds the URL at read time.
- The files folder needs its own `.htaccess` with
  `Header set Access-Control-Allow-Origin "*"` so the GitHub Pages front end can
  fetch a file to force a real download (a cross-origin `<a download>` is
  otherwise ignored by browsers). Without it, Download falls back to opening the
  file in a new tab.

## Sibling apps on the same MyDataWorld database

- **My Apps Hub** — central login, signup/invitations, per-app access grants.
- **SoJo member app** (`SoJoMemberApp`) — the single-choir app this one's
  architecture is modeled on; its tables are `choir_*` (unrelated to `cc_*`).
- **T-Minus**, **PWI Weight Tracker**, **Shed Inventory**, **Scripture Learning**,
  and others — unrelated apps sharing the DB, kept apart by table-name prefixes
  and `app_access` grants.

## Planned (not yet built)

See `ROADMAP.md` for the full plan. Next up: **Member List** (per-choir roster,
contact cards, singer self-service hide of their own address/phone/email).
