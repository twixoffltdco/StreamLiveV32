<?php
/**
 * Flex — presence на ВСЕЙ платформе.
 * Ключ: room + device_id (один аккаунт на 2 устройствах = 2 зрителя).
 * activity: что делает (видео / канал / форум / статья / …).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

$u = function_exists('current_user') ? current_user() : null;
$raw = file_get_contents('php://input');
$j = json_decode($raw ?: '[]', true);
if (!is_array($j)) $j = [];

$room = (string)($j['room'] ?? $_GET['room'] ?? 'home');
$room = mb_substr(preg_replace('/[^\p{L}\p{N}:_\/\.\-\?\=\&]/u', '', $room), 0, 160);
if ($room === '') $room = 'home';

$device = preg_replace('/[^a-zA-Z0-9_\-]{1,64}/', '', (string)($j['device'] ?? ''));
if ($device === '' || $device === 'unknown') {
  // fallback session device
  if (empty($_SESSION['fx_dev'])) {
    $_SESSION['fx_dev'] = 's_' . bin2hex(random_bytes(8));
  }
  $device = (string)$_SESSION['fx_dev'];
}

$afk = !empty($j['afk']) ? 1 : 0;
$leave = !empty($_GET['leave']) || !empty($j['leave']);
$paid = !empty($j['paid']) ? 1 : 0;

$activity = trim((string)($j['activity'] ?? ''));
$activity = mb_substr(preg_replace('/[\x00-\x1f]/', '', $activity), 0, 80);
if ($activity === '') $activity = 'на сайте';
if ($paid) $activity = '🔒 платный контент';

$name = trim((string)($j['name'] ?? ''));
$guest = !$u;
if ($u) {
  $uid = (int)$u['id'];
  $name = (string)($u['username'] ?? ($name !== '' ? $name : 'User'));
} else {
  $uid = 0;
  if ($name === '' || preg_match('/^guest$/i', $name)) {
    $name = 'Guest' . substr((string)abs(crc32($device)), -4);
  }
}
$name = mb_substr($name, 0, 24);

try {
  $pdo = db();
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS flex_presence (
      device_id VARCHAR(64) NOT NULL,
      room_key VARCHAR(160) NOT NULL DEFAULT 'home',
      user_id INT NOT NULL DEFAULT 0,
      display_name VARCHAR(32) NOT NULL DEFAULT '',
      is_guest TINYINT(1) NOT NULL DEFAULT 0,
      is_afk TINYINT(1) NOT NULL DEFAULT 0,
      is_paid TINYINT(1) NOT NULL DEFAULT 0,
      activity VARCHAR(80) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (device_id),
      KEY idx_room (room_key, updated_at),
      KEY idx_time (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
  // migrate old schema if needed
  try {
    $cols = $pdo->query('SHOW COLUMNS FROM flex_presence')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('activity', $cols, true)) {
      $pdo->exec("ALTER TABLE flex_presence ADD COLUMN activity VARCHAR(80) NOT NULL DEFAULT ''");
    }
    if (!in_array('is_paid', $cols, true)) {
      $pdo->exec("ALTER TABLE flex_presence ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0");
    }
  } catch (Throwable $e) {}
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'schema: ' . $e->getMessage()]);
  exit;
}

try {
  db()->exec('DELETE FROM flex_presence WHERE updated_at < (NOW() - INTERVAL 60 SECOND)');
} catch (Throwable $e) {}

if ($leave) {
  try {
    db()->prepare('DELETE FROM flex_presence WHERE device_id=?')->execute([$device]);
  } catch (Throwable $e) {}
  echo json_encode(['ok' => true, 'left' => true]);
  exit;
}

try {
  db()->prepare(
    'INSERT INTO flex_presence (device_id, room_key, user_id, display_name, is_guest, is_afk, is_paid, activity, updated_at)
     VALUES (?,?,?,?,?,?,?,?,NOW())
     ON DUPLICATE KEY UPDATE
       room_key=VALUES(room_key), user_id=VALUES(user_id), display_name=VALUES(display_name),
       is_guest=VALUES(is_guest), is_afk=VALUES(is_afk), is_paid=VALUES(is_paid),
       activity=VALUES(activity), updated_at=NOW()'
  )->execute([$device, $room, $uid, $name, $guest ? 1 : 0, $afk, $paid, $activity]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}

// same room viewers (exclude me by device)
$sameRoom = [];
$global = [];
try {
  $st = db()->prepare(
    'SELECT device_id, room_key, user_id, display_name AS name, is_guest AS guest, is_afk AS afk,
            is_paid AS paid, activity
     FROM flex_presence
     WHERE device_id <> ? AND updated_at > (NOW() - INTERVAL 25 SECOND)
     ORDER BY updated_at DESC LIMIT 40'
  );
  $st->execute([$device]);
  $all = $st->fetchAll() ?: [];
  foreach ($all as $row) {
    $row['guest'] = !empty($row['guest']);
    $row['afk'] = !empty($row['afk']);
    $row['paid'] = !empty($row['paid']);
    if (($row['room_key'] ?? '') === $room) {
      $sameRoom[] = $row;
    }
    $global[] = $row;
  }
} catch (Throwable $e) {}

echo json_encode([
  'ok' => true,
  'room' => $room,
  'viewers' => $sameRoom,
  'platform' => $global,
  'me' => ['device' => $device, 'name' => $name, 'guest' => $guest],
], JSON_UNESCAPED_UNICODE);
