# Setup Guide

Choir Connect is backed by **MyDataWorld**, the same shared database and single
sign-on as My Apps Hub, T-Minus, and the SoJo member app. Every page — member and
admin — requires a MyDataWorld login with an `app_access` grant (`choir-connect`
for member pages, `choir-connect-admin` for `admin.html`), and every content
request also checks a `cc_choir_users` row scoping that email to the choir.

## 1. Register the two apps with My Apps Hub

In **phpMyAdmin**, select the MyDataWorld database, open the **SQL** tab, and run
the "NEW APP: Choir Connect" block appended to **My Apps Hub's** own
`api/schema.sql` (it adds `choir-connect` and `choir-connect-admin` to the `apps`
table). Safe to re-run — it's `ON DUPLICATE KEY UPDATE`.

## 2. Create this app's tables

Still in phpMyAdmin's SQL tab, run everything in [`api/schema.sql`](api/schema.sql).
It's safe to run even if `users`/`sessions` already exist (those use
`CREATE TABLE IF NOT EXISTS`). It seeds one choir — **The DeciBelles**
(`choir_number = 1`, `folder_slug = 'DeciBelles'`) — and a sample program.

## 3. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real
   `DB_NAME`, `DB_USER`, `DB_PASS` (same credentials as your other MyDataWorld
   apps) and `SONG_FILES_BASE_URL`. `PHOTO_UPLOAD_DIR` can stay `''` for now —
   set it later when you turn on member photo uploads (step 11).
2. Upload the whole `api/` folder via FTP/File Manager — e.g.
   `seniorfamily.org/choir-connect-api/`.

## 4. Point the site at your API

In `js/api.js`:

```js
const CONFIG = {
  API_URL: "https://seniorfamily.org/choir-connect-api/api.php",
};
```

Every page reads this one constant.

## 5. Publish

Create a GitHub repo `choir-connect`, push this folder, and enable
Settings → Pages → Deploy from a branch → `main` / `/ (root)`. The app will be
live at `https://dsshrek-ai.github.io/choir-connect/`.

## 6. Grant yourself access

1. Sign up through **My Apps Hub** with your email if you haven't already.
2. In the Hub's `admin.html`, grant your account both **Choir Connect** and
   **Choir Connect Admin** (or run the commented `INSERT` at the bottom of
   `api/schema.sql`).
3. Add yourself to a choir so the app has something to show — in Choir Connect's
   `admin.html` pick **ChoirUsers** and add a row `ChoirNumber = 1`,
   `UserEmail = you@example.com` (or run the commented `INSERT` in
   `api/schema.sql`).

## 7. Add real content (admin.html)

**Pick a choir first.** When you open the panel, choose the choir you're working
on from the **Choir** dropdown, or click **+ New choir** (enter a choir number,
name, and folder slug). Everything below is then scoped to that choir — you never
type a choir number again, and each table only shows that choir's rows. Switch
choirs any time from the same dropdown.

Then work through the **Table** dropdown:

- **Choir details** — the selected choir's `Name`, `FolderSlug` (the folder under
  `SONG_FILES_BASE_URL` holding its `Songs/` folder, e.g. `DeciBelles`) and
  `Index` (order in the member app's choir picker). The choir number can't be
  changed here.
- **Programs** — a program is a concert / season / set list. `Sequence` controls
  order: the **highest Sequence shows first** ("most recent program on top").
- **Rehearsal tracks** — one row per audio file. Rows with the same `SongNumber`
  are grouped as one song (`SongTitle` labels that group). `TrackName` is the
  part label (e.g. "Soprano"); `TrackSequence` orders tracks within a song.
  `SongFolder` + `FileName` locate the file (next step).
- **Choir users** — the apps-hub emails allowed to open this choir in the member
  app. They also need the **Choir Connect** app granted in My Apps Hub.

## 8. Upload rehearsal track files

Files are real uploads, not links. Under `SONG_FILES_BASE_URL`, create this tree
via FTP/File Manager:

```
<SONG_FILES_BASE_URL>/<choir FolderSlug>/Songs/<SongFolder>/<FileName>
```

e.g. for The DeciBelles, a song in folder `Bring-Him-Home` with a soprano track:

```
choir-connect-files/DeciBelles/Songs/Bring-Him-Home/soprano.mp3
```

Then in **RehearsalTracks** set `SongFolder = Bring-Him-Home` and
`FileName = soprano.mp3` on that row. `api.php` builds the URL
`SONG_FILES_BASE_URL/DeciBelles/Songs/Bring-Him-Home/soprano.mp3` at read time.

### Let Download save the file instead of opening it

`program.html` is served from GitHub Pages, a different origin than the files on
`seniorfamily.org`, so the Download button fetches the file via JavaScript and
saves it — which needs the file host to allow cross-origin reads. Create/upload an
`.htaccess` **inside the `SONG_FILES_BASE_URL` folder** (e.g.
`choir-connect-files/.htaccess`) containing:

```
<IfModule mod_headers.c>
  Header set Access-Control-Allow-Origin "*"
</IfModule>
```

Until this is in place, Download falls back to opening the file in a new tab.

## 9. Volunteer sign-ups

The Volunteer page (`volunteer.html`) lets members claim slots on volunteer needs
the admin posts, and cancel their own signup. It was added after the first
deploy, so:

1. **Database** — run the `cc_volunteer_tasks` / `cc_volunteer_signups`
   `CREATE TABLE`s from `api/schema.sql` (the "SCHEMA CHANGE: Volunteer sign-ups"
   block) in phpMyAdmin.
2. **Redeploy** — re-upload `api/api.php`, and push the repo so GitHub Pages picks
   up `volunteer.html`, `js/api.js` (nav link), `style.css`, and `admin.html`.
3. **Post needs** — in `admin.html`, pick the choir, then **Volunteer tasks**:
   add a row per need — an optional `Date`, `TaskName`, `SlotsNeeded`, `Index`
   (list order). Members then sign up from the Volunteer page; their signups show
   under **Volunteer signups** (delete a row there to free a slot).

`Email` on a signup is the member's login email — that's how "Cancel my signup"
finds their row. No notification email is sent in v1.

## 10. Schedule

The Schedule page (`schedule.html`) shows a choir's rehearsals and events, newest
first, with past events kept on the list. Added after the Volunteer feature, so:

1. **Database** — run the `cc_schedule` `CREATE TABLE` from `api/schema.sql` (the
   "SCHEMA CHANGE: Schedule" block) in phpMyAdmin.
2. **Redeploy** — re-upload `api/api.php`, and push the repo so GitHub Pages picks
   up `schedule.html`, `js/api.js` (nav link), `style.css`, and `admin.html`.
3. **Add events** — in `admin.html`, pick the choir, then **Schedule**: add a row
   per event — `Date`, `Time` (free text like "7:00 PM"), `Title`, `Location`,
   `MapUrl` (leave blank to auto-link the Location to Google Maps, or paste a
   specific link), `Instructions`, `Notes`, `Index` (order among same-day events).

## 11. Member List

The Members page (`members.html`) shows a choir's roster. Added after Schedule, so:

1. **Database** — run the `cc_members` `CREATE TABLE` from `api/schema.sql` (the
   "SCHEMA CHANGE: Member List" block) in phpMyAdmin.
2. **Redeploy** — re-upload `api/api.php`, and push the repo so GitHub Pages picks
   up `members.html`, `js/api.js` (nav link), `style.css`, and `admin.html`.
3. **Add members** — in `admin.html`, pick the choir, then **Members**: one row
   per singer — `Name`, `Status` (Current / Past), `Address`, `Phone`, `Email`,
   `PhotoUrl`, `HideAddress` / `HidePhone` / `HideEmail` (No / Yes), `Index`.
   You mainly need `Name` and `Email` — the singer fills in the rest themselves
   (next point).

- **Status "Past"** hides the member on the page unless the viewer ticks
  **"Show past members"** (default off; the choice is remembered per browser).
- **`Email`** must match the singer's login email. When it does, that singer can
  edit their own **Name / Address / Phone / Photo** and the **Hide** flags from
  the Members page — their edits apply to every choir they're on. Email and
  Status are never editable by the member (email is the login; Status is yours).
- Hidden fields are removed from the API for everyone except that singer viewing
  their own card; the admin kiosk always sees everything.
- **`PhotoUrl`**: a bare filename means a file in the Photos folder
  (member-uploaded, or one you FTP in yourself); a full `http(s)` link is used
  as-is.

### Turn on member photo uploads

1. Create a **`Photos`** folder inside your `choir-connect-files` folder (the one
   `SONG_FILES_BASE_URL` points at) and make it writable by PHP.
2. Set `PHOTO_UPLOAD_DIR` in `api/config.php` to that folder's **server path**
   (e.g. `/home/ACCOUNT/public_html/choir-connect-files/Photos`), then re-upload
   `api/config.php`.
3. That's it — the "Add / Change photo" button appears on each singer's own card.
   Uploads are capped at 4 MB and must be JPG / PNG / WebP (the page also
   downscales large images before sending). One photo per person, shared across
   their choirs. Leaving `PHOTO_UPLOAD_DIR` blank just hides the upload button;
   you can still paste a `PhotoUrl` in the admin kiosk.

## Single sign-on

Both apps are seeded with `sso_enabled = 1`, so launching either from My Apps Hub
skips its login screen (`?token=...` handoff). The admin panel's login is
session-scoped (`sessionStorage`) even with SSO, since it edits real data and may
run on a shared computer — it clears when the tab closes.

## Usage logging

Every successful member action upserts a row into the shared `app_usage_log`
(`app_key = 'choir-connect'`), one row per user per day. Usage by day:

```sql
SELECT access_date, COUNT(DISTINCT user_id) AS active_members
FROM app_usage_log
WHERE app_key = 'choir-connect'
GROUP BY access_date
ORDER BY access_date;
```

## Planned features (not in this build)

- **Configurable nav** — a `cc_nav_items` table so the top nav is admin-editable
  instead of the fixed `NAV_ITEMS` list in `js/api.js`.

See `ROADMAP.md` for details.
