# Choir Connect — Roadmap

Status of the app and what's planned next. See `STACK.md` for architecture and
`SETUP.md` for deployment.

## Shipped

- **Rehearsal Tracks** — `programs.html` → `program.html` (song list) →
  `song.html` (part tracks, play / download). Multi-choir throughout.
- **Choir picker** — header switcher when a member is in more than one choir.
- **Volunteer sign-ups** — `volunteer.html`: per-choir volunteer needs, sign up
  for a slot (named-lock so the last slot can't double-book), cancel your own
  signup. No notification email in v1. API: `volunteerStatus` / `claimSlot` /
  `releaseSlot`; tables `cc_volunteer_tasks`, `cc_volunteer_signups`; admin
  editors `VolunteerTasks` / `VolunteerSignups`.
- **Admin kiosk** (`admin.html`) — generic editor for Choirs, Programs,
  RehearsalTracks, ChoirUsers (assign users to choirs), VolunteerTasks,
  VolunteerSignups.
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

### 1. Schedule

`cc_schedule (choir_number, event_date, time_text, location, map_url, instructions,
notes, idx)`. `schedule.html` sorts by date + time **descending, including past
events**; Location becomes a Google Maps link
(`https://www.google.com/maps/search/?api=1&query=<encoded>`). Admin registry
entry `Schedule`; reuses the `$DATE_COLUMNS` handling from the Volunteer work.

### 2. Member List

`cc_members (choir_number, name, address, phone, email, photo_url, hide_address,
hide_phone, hide_email, sort_order)`. `members.html` renders contact cards with
Call / Text / Email buttons (`contactButtonsHtml`, already in `js/api.js`) and a
maps link for the address. Admin maintains the rows; a singer whose login email
matches their row gets a small `patchMyPrivacy` action toggling only their three
`hide_*` flags. Photo = pasted image URL.

### 3. Configurable nav (if the fixed nav becomes limiting)

`cc_nav_items` + a `navItems` action, mirroring the member app's
`choir_nav_items`, so the top nav is admin-editable instead of the `NAV_ITEMS`
constant in `js/api.js`.
