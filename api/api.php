<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Never let a raw PHP error/notice leak through as HTML -- every response
// this API sends must be JSON.
ini_set('display_errors', '0');
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['ok' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// ---- Auth -- shared MyDataWorld login (bearer token vs the sessions table),
// ---- then a per-app app_access grant, then (for content) a per-choir grant --

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $token = $m[1];

  $stmt = db()->prepare(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$result) {
    fail('Session expired or invalid -- please log in again', 401);
  }
  return $result;
}

function hasAppAccess(array $user, string $appKey): bool {
  $stmt = db()->prepare(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id
     WHERE aa.user_id = ? AND a.app_key = ?'
  );
  $stmt->bind_param('is', $user['id'], $appKey);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_row();
  $stmt->close();
  return (bool)$ok;
}

function requireChoirConnectAdminAccess(array $user): void {
  if (!hasAppAccess($user, 'choir-connect-admin')) {
    fail('Not authorized for the Choir Connect Admin panel', 403);
  }
}

// Best-effort usage logging (see app_usage_log in My Apps Hub's schema.sql --
// shared across every MyDataWorld app, one row per user per app per day,
// upserted on each successful access). Wrapped so a missing table never
// breaks a member-facing request.
function logAppUsage(int $userId): void {
  try {
    $appKey = 'choir-connect';
    $stmt = db()->prepare(
      'INSERT INTO app_usage_log (user_id, app_key, access_date, first_seen_at, last_seen_at, hit_count)
       VALUES (?, ?, CURDATE(), NOW(), NOW(), 1)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hit_count = hit_count + 1'
    );
    $stmt->bind_param('is', $userId, $appKey);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    // Best-effort -- see comment above.
  }
}

// Every member-facing action calls this first: a valid MyDataWorld login,
// plus an app_access grant for 'choir-connect' specifically.
function requireMember(): array {
  $user = requireUser();
  if (!hasAppAccess($user, 'choir-connect')) {
    fail('Not authorized for Choir Connect', 403);
  }
  logAppUsage((int)$user['id']);
  return $user;
}

// The choir numbers this user's email is mapped to in cc_choir_users.
function userChoirNumbers(array $user): array {
  $stmt = db()->prepare('SELECT choir_number FROM cc_choir_users WHERE user_email = ?');
  $stmt->bind_param('s', $user['username']);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = (int)$row['choir_number'];
  }
  $stmt->close();
  return $out;
}

// A content action names a choir (?choir=N); the user must be mapped to it.
function requireChoirAccess(array $user, int $choirNumber): void {
  if ($choirNumber <= 0 || !in_array($choirNumber, userChoirNumbers($user), true)) {
    fail('You do not have access to that choir', 403);
  }
}

// ---- Admin table registry: admin-panel name -> MySQL table + PascalCase ----
// ---- JSON key -> snake_case column. Same shape as the member app's.      ----

$DATA_TABLES = [
  'Choirs' => ['table' => 'cc_choirs', 'columns' => [
    'ChoirNumber' => 'choir_number', 'Name' => 'name', 'FolderSlug' => 'folder_slug', 'Index' => 'idx',
  ]],
  'Programs' => ['table' => 'cc_programs', 'columns' => [
    'ChoirNumber' => 'choir_number', 'ProgramNumber' => 'program_number', 'Name' => 'name',
    'Sequence' => 'sequence', 'Index' => 'idx',
  ]],
  'RehearsalTracks' => ['table' => 'cc_rehearsal_tracks', 'columns' => [
    'ChoirNumber' => 'choir_number', 'ProgramNumber' => 'program_number', 'SongNumber' => 'song_number',
    'SongTitle' => 'song_title', 'SongFolder' => 'song_folder', 'TrackSequence' => 'track_sequence',
    'ShortDescription' => 'short_description', 'TrackName' => 'track_name', 'FileName' => 'filename',
    'Index' => 'idx',
  ]],
  'ChoirUsers' => ['table' => 'cc_choir_users', 'columns' => [
    'ChoirNumber' => 'choir_number', 'UserEmail' => 'user_email',
  ]],
  'VolunteerTasks' => ['table' => 'cc_volunteer_tasks', 'columns' => [
    'ChoirNumber' => 'choir_number', 'Date' => 'entry_date', 'TaskName' => 'task_name',
    'SlotsNeeded' => 'slots_needed', 'Index' => 'idx',
  ]],
  'VolunteerSignups' => ['table' => 'cc_volunteer_signups', 'columns' => [
    'ChoirNumber' => 'choir_number', 'Date' => 'entry_date', 'TaskName' => 'task_name',
    'VolunteerName' => 'volunteer_name', 'PhoneNumber' => 'phone_number', 'Email' => 'email',
    'Timestamp' => 'signed_up_at',
  ]],
  'Schedule' => ['table' => 'cc_schedule', 'columns' => [
    'ChoirNumber' => 'choir_number', 'Date' => 'event_date', 'Time' => 'time_text',
    'Title' => 'title', 'Location' => 'location', 'MapUrl' => 'map_url',
    'Instructions' => 'instructions', 'Notes' => 'notes', 'Index' => 'idx',
  ]],
  'Members' => ['table' => 'cc_members', 'columns' => [
    'ChoirNumber' => 'choir_number', 'Name' => 'name', 'Status' => 'status',
    'Address' => 'address', 'Phone' => 'phone', 'Email' => 'email', 'PhotoUrl' => 'photo_url',
    'HideAddress' => 'hide_address', 'HidePhone' => 'hide_phone', 'HideEmail' => 'hide_email',
    'Index' => 'idx',
  ]],
];

// Columns that must round-trip as JSON numbers, not strings.
$NUMERIC_COLUMNS = ['ChoirNumber', 'ProgramNumber', 'SongNumber', 'TrackSequence', 'Sequence',
  'SlotsNeeded', 'HideAddress', 'HidePhone', 'HideEmail', 'Index'];

// Columns backed by a real SQL DATE column: MySQL rejects '' for a DATE, so a
// blank date field is stored as NULL instead.
$DATE_COLUMNS = ['Date'];

function tableRowsWithId(string $name): array {
  global $DATA_TABLES;
  $spec = $DATA_TABLES[$name];
  $result = db()->query("SELECT * FROM {$spec['table']} ORDER BY id");
  $rows = $result->fetch_all(MYSQLI_ASSOC);
  return array_map(function ($row) use ($spec) {
    $shaped = ['_row' => (int)$row['id']];
    foreach ($spec['columns'] as $jsonKey => $dbCol) {
      $shaped[$jsonKey] = $row[$dbCol];
    }
    return $shaped;
  }, $rows);
}

function adminInsertRow(string $name, array $rowData): int {
  global $DATA_TABLES, $NUMERIC_COLUMNS, $DATE_COLUMNS;
  $spec = $DATA_TABLES[$name];
  $cols = []; $placeholders = []; $types = ''; $values = [];
  foreach ($spec['columns'] as $jsonKey => $dbCol) {
    $cols[] = $dbCol;
    $placeholders[] = '?';
    if (in_array($jsonKey, $NUMERIC_COLUMNS, true)) {
      $types .= 'i';
      $values[] = (int)($rowData[$jsonKey] ?? 0);
    } elseif (in_array($jsonKey, $DATE_COLUMNS, true)) {
      $types .= 's';
      $values[] = ($rowData[$jsonKey] ?? '') === '' ? null : (string)$rowData[$jsonKey];
    } else {
      $types .= 's';
      $values[] = (string)($rowData[$jsonKey] ?? '');
    }
  }
  $sql = "INSERT INTO {$spec['table']} (" . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
  $stmt = db()->prepare($sql);
  $stmt->bind_param($types, ...$values);
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return $id;
}

function adminUpdateRow(string $name, int $id, array $rowData): void {
  global $DATA_TABLES, $NUMERIC_COLUMNS, $DATE_COLUMNS;
  $spec = $DATA_TABLES[$name];
  $sets = []; $types = ''; $values = [];
  foreach ($spec['columns'] as $jsonKey => $dbCol) {
    $sets[] = "$dbCol = ?";
    if (in_array($jsonKey, $NUMERIC_COLUMNS, true)) {
      $types .= 'i';
      $values[] = (int)($rowData[$jsonKey] ?? 0);
    } elseif (in_array($jsonKey, $DATE_COLUMNS, true)) {
      $types .= 's';
      $values[] = ($rowData[$jsonKey] ?? '') === '' ? null : (string)$rowData[$jsonKey];
    } else {
      $types .= 's';
      $values[] = (string)($rowData[$jsonKey] ?? '');
    }
  }
  $types .= 'i';
  $values[] = $id;
  $sql = "UPDATE {$spec['table']} SET " . implode(', ', $sets) . ' WHERE id = ?';
  $stmt = db()->prepare($sql);
  $stmt->bind_param($types, ...$values);
  $stmt->execute();
  $stmt->close();
}

function adminDeleteRow(string $name, int $id): void {
  global $DATA_TABLES;
  $spec = $DATA_TABLES[$name];
  $stmt = db()->prepare("DELETE FROM {$spec['table']} WHERE id = ?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $stmt->close();
}

// ---- Member reads ----

// The choirs this user may see, for the header picker. { ChoirNumber, Name }.
function memberChoirs(array $user): array {
  $stmt = db()->prepare(
    'SELECT c.choir_number, c.name
     FROM cc_choirs c JOIN cc_choir_users cu ON cu.choir_number = c.choir_number
     WHERE cu.user_email = ?
     ORDER BY c.idx, c.choir_number'
  );
  $stmt->bind_param('s', $user['username']);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = ['ChoirNumber' => (int)$row['choir_number'], 'Name' => $row['name']];
  }
  $stmt->close();
  return $out;
}

// Programs for one choir, newest first. { ProgramNumber, Name, Sequence }.
function choirPrograms(int $choirNumber): array {
  $stmt = db()->prepare(
    'SELECT program_number, name, sequence
     FROM cc_programs WHERE choir_number = ?
     ORDER BY sequence DESC, program_number DESC'
  );
  $stmt->bind_param('i', $choirNumber);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      'ProgramNumber' => (int)$row['program_number'],
      'Name' => $row['name'],
      'Sequence' => (int)$row['sequence'],
    ];
  }
  $stmt->close();
  return $out;
}

// A choir's schedule, newest first, including past events. Location resolves
// to MapLink: the admin's pasted Map URL if any, else a Google Maps search
// on the location text, else null.
// [{ Date, Time, Title, Location, MapLink, Instructions, Notes }]
function choirSchedule(int $choirNumber): array {
  $stmt = db()->prepare(
    'SELECT event_date, time_text, title, location, map_url, instructions, notes
     FROM cc_schedule WHERE choir_number = ?
     ORDER BY event_date IS NULL, event_date DESC, idx, id'
  );
  $stmt->bind_param('i', $choirNumber);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $location = trim((string)($row['location'] ?? ''));
    $mapUrl = trim((string)($row['map_url'] ?? ''));
    $mapLink = null;
    if ($mapUrl !== '') {
      $mapLink = $mapUrl;
    } elseif ($location !== '') {
      $mapLink = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($location);
    }
    $out[] = [
      'Date' => $row['event_date'] ?? '',
      'Time' => (string)($row['time_text'] ?? ''),
      'Title' => (string)($row['title'] ?? ''),
      'Location' => $location,
      'MapLink' => $mapLink,
      'Instructions' => (string)($row['instructions'] ?? ''),
      'Notes' => (string)($row['notes'] ?? ''),
    ];
  }
  $stmt->close();
  return $out;
}

// Resolves a stored photo_url to something the browser can load: an absolute
// URL is passed through as-is; anything else is treated as a filename in the
// Photos folder (SONG_FILES_BASE_URL/Photos/<name>). '' if nothing usable.
function memberPhotoUrl(string $stored): string {
  $stored = trim($stored);
  if ($stored === '') {
    return '';
  }
  if (preg_match('#^https?://#i', $stored)) {
    return $stored;
  }
  if (defined('SONG_FILES_BASE_URL') && SONG_FILES_BASE_URL !== '') {
    return rtrim(SONG_FILES_BASE_URL, '/') . '/Photos/' . rawurlencode($stored);
  }
  return '';
}

// A choir's member roster. Past members are left out unless $includePast.
// Each row hides address / phone / email that the member chose to hide --
// EXCEPT the caller's own row (matched by login email), which comes back in
// full plus IsMe = true and the raw HideAddress/HidePhone/HideEmail flags so
// the page can render the privacy toggles.
function choirMembers(int $choirNumber, string $meEmail, bool $includePast): array {
  $sql = 'SELECT name, status, address, phone, email, photo_url, hide_address, hide_phone, hide_email
          FROM cc_members WHERE choir_number = ?';
  if (!$includePast) {
    $sql .= " AND status <> 'Past'";
  }
  // Sorted by name (a single field, so effectively by first name).
  $sql .= ' ORDER BY name, id';
  $stmt = db()->prepare($sql);
  $stmt->bind_param('i', $choirNumber);
  $stmt->execute();
  $res = $stmt->get_result();
  $me = strtolower(trim($meEmail));
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $rowEmail = trim((string)($row['email'] ?? ''));
    $isMe = $me !== '' && strtolower($rowEmail) === $me;

    $address = trim((string)($row['address'] ?? ''));
    $phone = trim((string)($row['phone'] ?? ''));
    $showAddress = $isMe || (int)$row['hide_address'] === 0;
    $showPhone = $isMe || (int)$row['hide_phone'] === 0;
    $showEmail = $isMe || (int)$row['hide_email'] === 0;

    $shownAddress = $showAddress ? $address : '';
    $entry = [
      'Name' => (string)($row['name'] ?? ''),
      'Status' => (string)($row['status'] ?? ''),
      'PhotoUrl' => memberPhotoUrl((string)($row['photo_url'] ?? '')),
      'Address' => $shownAddress,
      'Phone' => $showPhone ? $phone : '',
      'Email' => $showEmail ? $rowEmail : '',
      'MapLink' => $shownAddress !== ''
        ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($shownAddress)
        : null,
      'IsMe' => $isMe,
    ];
    if ($isMe) {
      $entry['HideAddress'] = (int)$row['hide_address'];
      $entry['HidePhone'] = (int)$row['hide_phone'];
      $entry['HideEmail'] = (int)$row['hide_email'];
    }
    $out[] = $entry;
  }
  $stmt->close();
  return $out;
}

// True if the caller's login email is on at least one choir's roster.
function isOnRoster(string $meEmail): bool {
  if (trim($meEmail) === '') {
    return false;
  }
  $stmt = db()->prepare('SELECT 1 FROM cc_members WHERE email = ? LIMIT 1');
  $stmt->bind_param('s', $meEmail);
  $stmt->execute();
  $found = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $found;
}

// A member edits their own roster entry. Name / address / phone / the hide_*
// flags are applied to ALL of that member's rows (matched by login email), so
// their info stays the same across every choir. Email and Status are never
// touched here -- email is the login, Status is admin-only.
function updateMyProfile(string $meEmail, string $name, string $address, string $phone,
    int $hideAddress, int $hidePhone, int $hideEmail): array {
  if (!isOnRoster($meEmail)) {
    return ['ok' => false, 'reason' => 'not-on-roster'];
  }
  $name = trim($name);
  if ($name === '') {
    return ['ok' => false, 'reason' => 'name-required'];
  }
  $ha = $hideAddress ? 1 : 0;
  $hp = $hidePhone ? 1 : 0;
  $he = $hideEmail ? 1 : 0;
  $address = trim($address);
  $phone = trim($phone);
  $stmt = db()->prepare(
    'UPDATE cc_members SET name = ?, address = ?, phone = ?,
       hide_address = ?, hide_phone = ?, hide_email = ?
     WHERE email = ?'
  );
  $stmt->bind_param('sssiiis', $name, $address, $phone, $ha, $hp, $he, $meEmail);
  $stmt->execute();
  $stmt->close();
  return ['ok' => true];
}

// A member uploads their own photo. Written to PHOTO_UPLOAD_DIR with a filename
// keyed to a hash of their email (one photo per person), and photo_url on all
// their rows is set to that bare filename -- memberPhotoUrl() turns it into a
// URL under SONG_FILES_BASE_URL/Photos/ at read time.
function saveMyPhoto(string $meEmail, array $file): array {
  if (!isOnRoster($meEmail)) {
    return ['ok' => false, 'reason' => 'not-on-roster'];
  }
  if (!defined('PHOTO_UPLOAD_DIR') || PHOTO_UPLOAD_DIR === ''
      || !is_dir(PHOTO_UPLOAD_DIR) || !is_writable(PHOTO_UPLOAD_DIR)) {
    return ['ok' => false, 'reason' => 'not-configured'];
  }
  if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
      || !is_uploaded_file($file['tmp_name'])) {
    return ['ok' => false, 'reason' => 'no-file'];
  }
  if (($file['size'] ?? 0) > 4 * 1024 * 1024) {
    return ['ok' => false, 'reason' => 'too-big'];
  }

  // getimagesize both identifies the type and proves the bytes are a real,
  // decodable image (fileinfo extension not required).
  $info = @getimagesize($file['tmp_name']);
  $mime = is_array($info) ? ($info['mime'] ?? '') : '';
  $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  if (!isset($extByMime[$mime])) {
    return ['ok' => false, 'reason' => 'bad-type'];
  }
  $ext = $extByMime[$mime];
  $base = sha1(strtolower(trim($meEmail)));
  $filename = $base . '.' . $ext;

  // Drop any prior photo this person had under a different extension.
  foreach (['jpg', 'png', 'webp'] as $other) {
    if ($other !== $ext) {
      @unlink(rtrim(PHOTO_UPLOAD_DIR, '/') . '/' . $base . '.' . $other);
    }
  }

  if (!move_uploaded_file($file['tmp_name'], rtrim(PHOTO_UPLOAD_DIR, '/') . '/' . $filename)) {
    return ['ok' => false, 'reason' => 'write-failed'];
  }

  $stmt = db()->prepare('UPDATE cc_members SET photo_url = ? WHERE email = ?');
  $stmt->bind_param('ss', $filename, $meEmail);
  $stmt->execute();
  $stmt->close();

  return ['ok' => true, 'photoUrl' => memberPhotoUrl($filename)];
}

// One program's songs + tracks. Tracks are grouped into one entry per
// song_number (enter every part of a song with the same Song Number in the
// admin). SongTitle is the first non-blank title on any of that song's rows,
// or "Song N". Each track gets a real Url built from the choir's folder_slug
// + the track's song_folder + filename (null if SONG_FILES_BASE_URL isn't
// configured).
function programDetail(int $choirNumber, int $programNumber): array {
  $pstmt = db()->prepare('SELECT name FROM cc_programs WHERE choir_number = ? AND program_number = ?');
  $pstmt->bind_param('ii', $choirNumber, $programNumber);
  $pstmt->execute();
  $program = $pstmt->get_result()->fetch_assoc();
  $pstmt->close();
  if (!$program) {
    fail('Program not found', 404);
  }

  $cstmt = db()->prepare('SELECT folder_slug FROM cc_choirs WHERE choir_number = ?');
  $cstmt->bind_param('i', $choirNumber);
  $cstmt->execute();
  $choir = $cstmt->get_result()->fetch_assoc();
  $cstmt->close();
  $folderSlug = trim((string)($choir['folder_slug'] ?? ''));

  $base = defined('SONG_FILES_BASE_URL') ? rtrim(SONG_FILES_BASE_URL, '/') : '';

  $tstmt = db()->prepare(
    'SELECT song_number, song_title, song_folder, track_sequence, short_description, track_name, filename, idx
     FROM cc_rehearsal_tracks
     WHERE choir_number = ? AND program_number = ?
     ORDER BY song_number, track_sequence, idx, id'
  );
  $tstmt->bind_param('ii', $choirNumber, $programNumber);
  $tstmt->execute();
  $res = $tstmt->get_result();

  $songs = [];       // song_number => index into $ordered
  $ordered = [];
  while ($row = $res->fetch_assoc()) {
    $songNo = (int)$row['song_number'];
    $title = trim((string)($row['song_title'] ?? ''));
    if (!array_key_exists($songNo, $songs)) {
      $songs[$songNo] = count($ordered);
      $ordered[] = [
        'SongNumber' => $songNo,
        'SongTitle' => $title !== '' ? $title : ('Song ' . $songNo),
        'Tracks' => [],
      ];
    } elseif ($title !== '' && $ordered[$songs[$songNo]]['SongTitle'] === ('Song ' . $songNo)) {
      // First row for this song had no title; use one from a later row.
      $ordered[$songs[$songNo]]['SongTitle'] = $title;
    }
    $songFolder = trim((string)($row['song_folder'] ?? ''));
    $filename = trim((string)($row['filename'] ?? ''));
    $url = null;
    if ($base !== '' && $folderSlug !== '' && $songFolder !== '' && $filename !== '') {
      $url = $base . '/' . rawurlencode($folderSlug) . '/Songs/' . rawurlencode($songFolder) . '/' . rawurlencode($filename);
    }
    $ordered[$songs[$songNo]]['Tracks'][] = [
      'TrackName' => (string)($row['track_name'] ?? ''),
      'ShortDescription' => (string)($row['short_description'] ?? ''),
      'TrackSequence' => (int)$row['track_sequence'],
      'FileName' => $filename,
      'Url' => $url,
    ];
  }
  $tstmt->close();

  return ['ProgramName' => $program['name'], 'Songs' => $ordered];
}

// ---- Volunteer sign-ups (per choir) ----

// A choir's volunteer tasks, each with how many slots are filled and whether
// the current member (by login email) is one of them.
// [{ Date, TaskName, SlotsNeeded, SlotsFilled, YouSignedUp }]
function volunteerStatus(int $choirNumber, string $meEmail): array {
  $stmt = db()->prepare(
    "SELECT t.entry_date, t.task_name, t.slots_needed,
            (SELECT COUNT(*) FROM cc_volunteer_signups s
              WHERE s.choir_number = t.choir_number
                AND s.task_name = t.task_name
                AND s.entry_date <=> t.entry_date) AS slots_filled,
            (SELECT COUNT(*) FROM cc_volunteer_signups s
              WHERE s.choir_number = t.choir_number
                AND s.task_name = t.task_name
                AND s.entry_date <=> t.entry_date
                AND s.email = ?) AS mine
     FROM cc_volunteer_tasks t
     WHERE t.choir_number = ?
     ORDER BY t.entry_date IS NULL, t.entry_date, t.idx, t.id"
  );
  $stmt->bind_param('si', $meEmail, $choirNumber);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($row = $res->fetch_assoc()) {
    $out[] = [
      'Date' => $row['entry_date'] ?? '',
      'TaskName' => $row['task_name'],
      'SlotsNeeded' => (int)$row['slots_needed'],
      'SlotsFilled' => (int)$row['slots_filled'],
      'YouSignedUp' => ((int)$row['mine']) > 0,
    ];
  }
  $stmt->close();
  return $out;
}

// Claim one slot on a task. Named lock (like the member app's claimSlot) so
// two people can't take the last slot at once. Idempotent for a member who
// is already signed up.
function volunteerClaim(int $choir, string $date, string $task, string $name, string $phone, string $email): array {
  if ($task === '') {
    return ['ok' => false, 'reason' => 'not-found'];
  }
  $dateParam = $date === '' ? null : $date;
  $conn = db();
  $got = $conn->query("SELECT GET_LOCK('cc_claim_slot', 10) AS got")->fetch_assoc();
  if (!$got || (int)$got['got'] !== 1) {
    return ['ok' => false, 'reason' => 'locked'];
  }
  try {
    $tstmt = $conn->prepare(
      'SELECT slots_needed FROM cc_volunteer_tasks
       WHERE choir_number = ? AND task_name = ? AND entry_date <=> ? LIMIT 1'
    );
    $tstmt->bind_param('iss', $choir, $task, $dateParam);
    $tstmt->execute();
    $taskRow = $tstmt->get_result()->fetch_assoc();
    $tstmt->close();
    if (!$taskRow) {
      return ['ok' => false, 'reason' => 'not-found'];
    }

    $cstmt = $conn->prepare(
      'SELECT
         COUNT(*) AS filled,
         SUM(CASE WHEN email = ? THEN 1 ELSE 0 END) AS mine
       FROM cc_volunteer_signups
       WHERE choir_number = ? AND task_name = ? AND entry_date <=> ?'
    );
    $cstmt->bind_param('siss', $email, $choir, $task, $dateParam);
    $cstmt->execute();
    $counts = $cstmt->get_result()->fetch_assoc();
    $cstmt->close();

    if ($email !== '' && (int)($counts['mine'] ?? 0) > 0) {
      return ['ok' => true]; // already signed up -- no-op
    }
    if ((int)($counts['filled'] ?? 0) >= (int)$taskRow['slots_needed']) {
      return ['ok' => false, 'reason' => 'full'];
    }

    $ins = $conn->prepare(
      'INSERT INTO cc_volunteer_signups
         (choir_number, entry_date, task_name, volunteer_name, phone_number, email, signed_up_at)
       VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $ins->bind_param('isssss', $choir, $dateParam, $task, $name, $phone, $email);
    $ins->execute();
    $ins->close();
    return ['ok' => true];
  } finally {
    $conn->query("SELECT RELEASE_LOCK('cc_claim_slot')");
  }
}

// Cancel the caller's own signup for a task. Matches on the login email, so a
// member can only remove their own row.
function volunteerRelease(int $choir, string $date, string $task, string $meEmail): array {
  if ($meEmail === '') {
    return ['ok' => false, 'reason' => 'not-found'];
  }
  $dateParam = $date === '' ? null : $date;
  $stmt = db()->prepare(
    'DELETE FROM cc_volunteer_signups
     WHERE choir_number = ? AND task_name = ? AND entry_date <=> ? AND email = ?'
  );
  $stmt->bind_param('isss', $choir, $task, $dateParam, $meEmail);
  $stmt->execute();
  $stmt->close();
  return ['ok' => true];
}

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
// A file upload (uploadMyPhoto) comes in as multipart/form-data, so its action
// and fields live in $_POST, not a JSON body.
$isMultipart = $method === 'POST'
  && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0;
$body = ($method === 'POST' && !$isMultipart) ? jsonBody() : [];
$action = $method === 'GET'
  ? ($_GET['action'] ?? '')
  : ($isMultipart ? ($_POST['action'] ?? '') : ($body['action'] ?? ''));

switch ($action) {

  // -- Member reads (GET, login + app_access; content also needs choir access) --

  case 'choirs': {
    $user = requireMember();
    respond(memberChoirs($user));
  }

  case 'programs': {
    $user = requireMember();
    $choir = (int)($_GET['choir'] ?? 0);
    requireChoirAccess($user, $choir);
    respond(choirPrograms($choir));
  }

  case 'schedule': {
    $user = requireMember();
    $choir = (int)($_GET['choir'] ?? 0);
    requireChoirAccess($user, $choir);
    respond(choirSchedule($choir));
  }

  case 'members': {
    $user = requireMember();
    $choir = (int)($_GET['choir'] ?? 0);
    requireChoirAccess($user, $choir);
    $includePast = ($_GET['includePast'] ?? '') === '1';
    respond(choirMembers($choir, $user['username'], $includePast));
  }

  case 'program': {
    $user = requireMember();
    $choir = (int)($_GET['choir'] ?? 0);
    $program = (int)($_GET['program'] ?? 0);
    requireChoirAccess($user, $choir);
    if ($program <= 0) {
      fail('Missing program number', 400);
    }
    respond(programDetail($choir, $program));
  }

  // One song within a program: { ProgramName, Song: { SongNumber, SongTitle,
  // Tracks: [...] } }. Reuses programDetail() and picks out the one song by
  // its Song Number.
  case 'song': {
    $user = requireMember();
    $choir = (int)($_GET['choir'] ?? 0);
    $program = (int)($_GET['program'] ?? 0);
    $song = (int)($_GET['song'] ?? 0);
    requireChoirAccess($user, $choir);
    if ($program <= 0 || $song <= 0) {
      fail('Missing program or song number', 400);
    }
    $detail = programDetail($choir, $program);
    $match = null;
    foreach ($detail['Songs'] as $s) {
      if ((int)$s['SongNumber'] === $song) { $match = $s; break; }
    }
    if ($match === null) {
      fail('Song not found', 404);
    }
    respond(['ProgramName' => $detail['ProgramName'], 'Song' => $match]);
  }

  // -- Volunteer sign-ups (member) --

  case 'volunteerStatus': {
    $user = requireMember();
    $choir = (int)($_GET['choir'] ?? 0);
    requireChoirAccess($user, $choir);
    respond(volunteerStatus($choir, $user['username']));
  }

  case 'claimSlot': {
    $user = requireMember();
    $choir = (int)($body['choir'] ?? 0);
    requireChoirAccess($user, $choir);
    // The signup email is always the caller's login -- not client-supplied --
    // so "you're signed up" and cancel stay reliable.
    respond(volunteerClaim(
      $choir,
      trim((string)($body['date'] ?? '')),
      trim((string)($body['taskName'] ?? '')),
      trim((string)($body['volunteerName'] ?? '')),
      trim((string)($body['phoneNumber'] ?? '')),
      $user['username']
    ));
  }

  case 'releaseSlot': {
    $user = requireMember();
    $choir = (int)($body['choir'] ?? 0);
    requireChoirAccess($user, $choir);
    respond(volunteerRelease(
      $choir,
      trim((string)($body['date'] ?? '')),
      trim((string)($body['taskName'] ?? '')),
      $user['username']
    ));
  }

  // -- Member List: a singer maintains their own roster entry --

  case 'updateMyProfile': {
    $user = requireMember();
    $choir = (int)($body['choir'] ?? 0);
    requireChoirAccess($user, $choir);
    respond(updateMyProfile(
      $user['username'],
      (string)($body['name'] ?? ''),
      (string)($body['address'] ?? ''),
      (string)($body['phone'] ?? ''),
      (int)($body['hideAddress'] ?? 0),
      (int)($body['hidePhone'] ?? 0),
      (int)($body['hideEmail'] ?? 0)
    ));
  }

  case 'uploadMyPhoto': {
    $user = requireMember();
    $choir = (int)($_POST['choir'] ?? 0);
    requireChoirAccess($user, $choir);
    respond(saveMyPhoto($user['username'], $_FILES['photo'] ?? []));
  }

  // -- MyDataWorld login (shared with My Apps Hub / T-Minus / the member app) --

  case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }
    $stmt = db()->prepare('SELECT id, password_hash, display_name FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user['password_hash'] === null || !password_verify($password, $user['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $ins = db()->prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))');
    $ins->bind_param('sii', $token, $user['id'], $days);
    $ins->execute();
    $ins->close();
    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $stmt->close();
    }
    respond(['ok' => true]);
  }

  // Resolves a My Apps Hub SSO handoff token to the logged-in email, for the
  // captureSsoEmail() convenience in js/api.js. Best-effort: returns
  // {ok:false} rather than a 401 for a missing/expired token.
  case 'whoAmI': {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
      respond(['ok' => false]);
    }
    $stmt = db()->prepare(
      'SELECT u.username FROM sessions s JOIN users u ON u.id = s.user_id
       WHERE s.token = ? AND s.expires_at > NOW()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
      respond(['ok' => false]);
    }
    respond(['ok' => true, 'email' => $row['username']]);
  }

  // -- Admin panel (MyDataWorld login + app_access for 'choir-connect-admin') --

  case 'checkAccess': {
    $user = requireUser();
    requireChoirConnectAdminAccess($user);
    respond(['ok' => true, 'displayName' => $user['display_name']]);
  }

  case 'adminList': {
    $user = requireUser();
    requireChoirConnectAdminAccess($user);
    $name = (string)($body['sheet'] ?? '');
    if (!array_key_exists($name, $DATA_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    respond(['ok' => true, 'rows' => tableRowsWithId($name)]);
  }

  case 'adminAdd': {
    $user = requireUser();
    requireChoirConnectAdminAccess($user);
    $name = (string)($body['sheet'] ?? '');
    if (!array_key_exists($name, $DATA_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    $id = adminInsertRow($name, (array)($body['row'] ?? []));
    respond(['ok' => true, 'id' => $id]);
  }

  case 'adminUpdate': {
    $user = requireUser();
    requireChoirConnectAdminAccess($user);
    $name = (string)($body['sheet'] ?? '');
    if (!array_key_exists($name, $DATA_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    $rowId = (int)($body['row'] ?? 0);
    if ($rowId <= 0) {
      respond(['ok' => false, 'reason' => 'invalid-row']);
    }
    adminUpdateRow($name, $rowId, (array)($body['values'] ?? []));
    respond(['ok' => true]);
  }

  case 'adminDelete': {
    $user = requireUser();
    requireChoirConnectAdminAccess($user);
    $name = (string)($body['sheet'] ?? '');
    if (!array_key_exists($name, $DATA_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    $rowId = (int)($body['row'] ?? 0);
    if ($rowId <= 0) {
      respond(['ok' => false, 'reason' => 'invalid-row']);
    }
    adminDeleteRow($name, $rowId);
    respond(['ok' => true]);
  }

  default:
    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 404);
}
