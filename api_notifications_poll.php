<?php
/**
 * Poll непрочитанных уведомлений для браузерного push (когда вкладка открыта).
 * GET ?after=ID  → { ok, items:[{id,type,message,link,created_at}] }
 * POST mark_read / mark_all
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/poll_throttle.php';
if (!poll_throttle_check('notif_poll', 12, 60)) { exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user) {
  echo json_encode(['ok' => false, 'error' => 'login', 'items' => []]);
  exit;
}

notify_ensure_schema();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
  $action = (string)($input['action'] ?? '');
  try {
    if ($action === 'mark_all') {
      db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);
    } elseif ($action === 'mark_read') {
      $id = (int)($input['id'] ?? 0);
      if ($id > 0) {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
      }
    }
  } catch (Throwable $e) {}
  echo json_encode(['ok' => true]);
  exit;
}

$after = (int)($_GET['after'] ?? 0);
$items = [];
try {
  if ($after > 0) {
    $st = db()->prepare(
      'SELECT id, type, message, link, channel_id, created_at FROM notifications
       WHERE user_id = ? AND id > ? ORDER BY id ASC LIMIT 30'
    );
    $st->execute([$uid, $after]);
  } else {
    // первые запросы — только непрочитанные за последние 24ч
    $st = db()->prepare(
      "SELECT id, type, message, link, channel_id, created_at FROM notifications
       WHERE user_id = ? AND is_read = 0 AND created_at > (NOW() - INTERVAL 1 DAY)
       ORDER BY id DESC LIMIT 20"
    );
    $st->execute([$uid]);
  }
  $items = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $items = [];
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
