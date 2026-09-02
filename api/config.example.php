<?php
// Copy this file to config.php, fill in the real values, and upload config.php via FTP/File Manager.
// config.php is gitignored -- it should never be committed.

define('DB_HOST', 'localhost');
define('DB_NAME', 'PUT_YOUR_ACCOUNT_PREFIX_HERE_MyDataWorld');
define('DB_USER', 'PUT_YOUR_DB_USERNAME_HERE');
define('DB_PASS', 'PUT_YOUR_DB_PASSWORD_HERE');

// How long a login session stays valid.
define('SESSION_LIFETIME_DAYS', 30);

// From: address for any future notification email (unused by the core build --
// kept for parity with the sibling apps and the planned Schedule feature).
define('FROM_EMAIL', 'noreply@seniorfamily.org');

// Public URL of the rehearsal-track files root (no trailing slash). The tree
// underneath mirrors the folders you upload via FTP/File Manager:
//
//   SONG_FILES_BASE_URL / {choir FolderSlug} / Songs / {SongFolder} / {FileName}
//
// e.g. https://seniorfamily.org/choir-connect-files/DeciBelles/Songs/Bring-Him-Home/soprano.mp3
//
// The database stores only FolderSlug (per choir), SongFolder + FileName (per
// track) -- api.php builds the real URL from this constant at read time, so
// moving the files only means changing this one value.
define('SONG_FILES_BASE_URL', 'https://seniorfamily.org/choir-connect-files');

// Server filesystem path of the folder where member-uploaded roster photos are
// written (no trailing slash). Its public URL is SONG_FILES_BASE_URL/Photos/,
// so create a "Photos" folder inside the choir-connect-files folder and put its
// real path here, e.g. '/home/ACCOUNT/public_html/choir-connect-files/Photos'.
// PHP must be able to write to it. Leave '' to disable photo uploads -- the
// Members page then just won't offer the upload field.
define('PHOTO_UPLOAD_DIR', '');
