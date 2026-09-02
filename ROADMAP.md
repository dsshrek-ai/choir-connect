# Choir Connect — Roadmap

Status of the app and what's planned next. See `STACK.md` for architecture and
`SETUP.md` for deployment.

## Shipped

- **Rehearsal Tracks** — `programs.html` → `program.html` (song list) →
  `song.html` (part tracks, play / download). Multi-choir throughout.
- **Choir picker** — header switcher when a member is in more than one choir.
- **Admin kiosk** (`admin.html`) — generic editor for Choirs, Programs,
  RehearsalTracks, and **ChoirUsers** (assign users to choirs).
- **Two-layer auth** — My Apps Hub `app_access` grant (`choir-connect` /
  `choir-connect-admin`) + `cc_choir_users` per-choir scoping. Usage logged to
  the shared `app_usage_log`.

## Decisions log

### Songs are listed by Song Number (not by title)  — settled

The song list on `program.html` groups tracks by **`song_number`**. Enter every
part of one song with the **same Song Number** in the admin; `SongTitle` is taken
from the first row that has one (else "Song N").

A short-lived experiment grouped by song *title* instead (commit `f2fa42b`,
reverted) to work around a program that showed one song twice — but the real
cause was the same song's parts being entered under different Song Numbers.
Grouping by number is the model; the fix is consistent data entry.

## Planned

### 1. Volunteer sign-ups  — next

Mirrors the SoJo member app's Volunteer feature, scoped per choir: the admin
posts volunteer needs (a task, a date, a number of slots); members see what's
open and sign up for a slot.

**Database** (add to `api/schema.sql`, run by hand in phpMyAdmin):

```sql
CREATE TABLE IF NOT EXISTS cc_volunteer_tasks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  choir_number  INT NOT NULL,
  entry_date    DATE NULL,
  task_name     VARCHAR(255) NOT NULL,
  slots_needed  INT NOT NULL DEFAULT 0,
  idx           INT NOT NULL DEFAULT 0
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
  KEY ix_signups (choir_number, entry_date, task_name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**API** (`api/api.php`):

- `$DATA_TABLES` — add
  `VolunteerTasks` → `cc_volunteer_tasks`
  (`ChoirNumber, Date, TaskName, SlotsNeeded, Index`) and
  `VolunteerSignups` → `cc_volunteer_signups`
  (`ChoirNumber, Date, TaskName, VolunteerName, PhoneNumber, Email, Timestamp`).
  Add `SlotsNeeded` to `$NUMERIC_COLUMNS`.
- Reintroduce a `$DATE_COLUMNS` set (`Date`) and the empty-string → `NULL`
  handling in `adminInsertRow` / `adminUpdateRow` — MySQL rejects `''` for a
  `DATE` column. (The core build dropped this; Volunteer and Schedule both need
  it.)
- `volunteerStatus` (GET, `?choir=N`, `requireMember` + `requireChoirAccess`) —
  the choir's tasks each with `SlotsFilled` (count of matching signups):
  `[{ Date, TaskName, SlotsNeeded, SlotsFilled }]`.
- `claimSlot` (POST, `{ choir, date, taskName, volunteerName, phoneNumber, email }`,
  `requireMember` + `requireChoirAccess`) — `GET_LOCK('cc_claim_slot', 10)`,
  recompute the task's filled count, reject if full, `INSERT` the signup with
  `choir_number`, `RELEASE_LOCK`. Returns `{ ok }` or `{ ok:false, reason }`
  (`locked` / `not-found` / `full`). Same named-lock pattern as the member app's
  `claimSlot`.

**Front end:**

- New `volunteer.html` — `ensureActiveChoir()` → `fetchChoirData('volunteerStatus')`;
  list each task with "X of Y filled" and, when slots remain, a small form (name +
  phone; email pre-filled from `getSavedEmail()`). Submit →
  `postAction('claimSlot', { choir: getActiveChoir(), ... })`, then refresh the
  list. Model the layout on the member app's `volunteer.html`.
- `js/api.js` — add `{ Label: 'Volunteer', PageFile: 'volunteer.html' }` to
  `NAV_ITEMS`.
- `admin.html` — add `VolunteerTasks` and `VolunteerSignups` to `TABLES`; give
  `Date` an `<input type="date">` (a `DATE_COLUMNS` set) and `SlotsNeeded` a
  number input.

**Deploy:** run the two `CREATE TABLE`s in phpMyAdmin; re-upload `api/api.php`,
`admin.html`, `js/api.js`, and the new `volunteer.html`.

**Open questions:** does a signup need a per-choir "which section leader gets
notified" email (the member app sends one), or is a plain recorded signup enough
for v1? Should members be able to cancel their own signup?

### 2. Schedule

`cc_schedule (choir_number, event_date, time_text, location, map_url, instructions,
notes, idx)`. `schedule.html` sorts by date + time **descending, including past
events**; Location becomes a Google Maps link
(`https://www.google.com/maps/search/?api=1&query=<encoded>`). Admin registry
entry `Schedule`; reuses the `$DATE_COLUMNS` handling from the Volunteer work.

### 3. Member List

`cc_members (choir_number, name, address, phone, email, photo_url, hide_address,
hide_phone, hide_email, sort_order)`. `members.html` renders contact cards with
Call / Text / Email buttons (`contactButtonsHtml`, already in `js/api.js`) and a
maps link for the address. Admin maintains the rows; a singer whose login email
matches their row gets a small `patchMyPrivacy` action toggling only their three
`hide_*` flags. Photo = pasted image URL.

### 4. Configurable nav (if the fixed nav becomes limiting)

`cc_nav_items` + a `navItems` action, mirroring the member app's
`choir_nav_items`, so the top nav is admin-editable instead of the `NAV_ITEMS`
constant in `js/api.js`.
