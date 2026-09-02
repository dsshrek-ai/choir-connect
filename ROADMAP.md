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
- **Schedule** — `schedule.html`: per-choir rehearsals / events, newest first,
  past events kept (dimmed, under a "Past" divider). Location links to Google
  Maps (a pasted map URL wins, else a maps search on the location text).
  API: `schedule`; table `cc_schedule`; admin editor `Schedule`.
- **Member List** — `members.html`: per-choir roster with photo, contact
  buttons (Call / Text / Email), and a maps link for the address. Each member
  has a **Current / Past** status; the page hides Past members unless the
  viewer ticks **"Show past members"** (default off, choice remembered). A
  singer whose login email matches their row can hide their own address / phone
  / email from other members (`patchMyPrivacy`); hidden fields are stripped
  from the API for everyone except that singer and the admin kiosk. API:
  `members`; table `cc_members`; admin editor `Members`.
- **Admin kiosk** (`admin.html`) — generic editor for Choirs, Programs,
  RehearsalTracks, ChoirUsers (assign users to choirs), VolunteerTasks,
  VolunteerSignups, Schedule, Members.
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

### 1. Configurable nav (if the fixed nav becomes limiting)

`cc_nav_items` + a `navItems` action, mirroring the member app's
`choir_nav_items`, so the top nav is admin-editable instead of the `NAV_ITEMS`
constant in `js/api.js`.
