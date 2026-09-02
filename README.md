# Choir Connect

A per-choir app for singers: browse the choir's **rehearsal tracks** (programs →
songs → part tracks), with the schedule and member roster planned next. Built on
**MyDataWorld** — the same shared database and single sign-on as My Apps Hub,
T-Minus, and the SoJo member app.

Unlike the SoJo member app, Choir Connect is **multi-choir**: every content row
carries a choir number, and `cc_choir_users` maps an apps-hub email to the choirs
it may see. A singer in more than one choir picks the active choir at the top of
the app.

See [SETUP.md](SETUP.md) for deployment, [STACK.md](STACK.md) for how it fits
together, and [ROADMAP.md](ROADMAP.md) for what's shipped and what's planned
(Volunteer sign-ups is next).

## Files

- `index.html` — landing page: shows the active choir, links into Rehearsal Tracks
- `programs.html` — the choir's programs, newest first
- `program.html` — one program's song titles (pick one)
- `song.html` — all part tracks for the selected song (play / download)
- `admin.html` — maintenance kiosk: Choirs, Programs, RehearsalTracks, and
  **ChoirUsers** (assign users to choirs). Own login, gated on the
  `choir-connect-admin` app grant.
- `style.css` — shared styles (one file, no build step)
- `js/api.js` — shared fetch wrapper, SSO capture, nav + choir picker
- `api/api.php` — backend: shared MyDataWorld login + per-app + per-choir access
  checks, member read actions (`choirs` / `programs` / `program`), and the
  generic admin table CRUD
- `api/schema.sql` — the `cc_*` tables (run by hand in phpMyAdmin)
- `api/config.example.php` — copy to `api/config.php` with real DB credentials +
  `SONG_FILES_BASE_URL` (gitignored)
- `api/.htaccess` — CORS header so the GitHub Pages front end can call the API

## Rehearsal track files

Audio files are real uploads, not pasted links. Upload them via FTP/File Manager
under `SONG_FILES_BASE_URL` following this tree:

```
<SONG_FILES_BASE_URL>/<choir FolderSlug>/Songs/<SongFolder>/<FileName>
e.g.  .../choir-connect-files/DeciBelles/Songs/Bring-Him-Home/soprano.mp3
```

The database stores only `FolderSlug` (per choir, in `Choirs`), and `SongFolder` +
`FileName` (per track, in `RehearsalTracks`). `api.php` builds the real URL at
read time, so moving the files only means changing `SONG_FILES_BASE_URL`.
