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
];

// Columns that must round-trip as JSON numbers, not strings.
$NUMERIC_COLUMNS = ['ChoirNumber', 'ProgramNumber', 'SongNumber', 'TrackSequence', 'Sequence', 'Index'];

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
  global $DATA_TABLES, $NUMERIC_COLUMNS;
  $spec = $DATA_TABLES[$name];
  $cols = []; $placeholders = []; $types = ''; $values = [];
  foreach ($spec['columns'] as $jsonKey => $dbCol) {
    $cols[] = $dbCol;
    $placeholders[] = '?';
    if (in_array($jsonKey, $NUMERIC_COLUMNS, true)) {
      $types .= 'i';
      $values[] = (int)($rowData[$jsonKey] ?? 0);
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
  global $DATA_TABLES, $NUMERIC_COLUMNS;
  $spec = $DATA_TABLES[$name];
  $sets = []; $types = ''; $values = [];
  foreach ($spec['columns'] as $jsonKey => $dbCol) {
    $sets[] = "$dbCol = ?";
    if (in_array($jsonKey, $NUMERIC_COLUMNS, true)) {
      $types .= 'i';
      $values[] = (int)($rowData[$jsonKey] ?? 0);
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

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? jsonBody() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');

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
