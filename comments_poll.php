<?php
if (is_file(__DIR__."/includes/poll_throttle.php")) { require_once __DIR__."/includes/poll_throttle.php"; poll_throttle("comments_poll", 180); }

if (is_file(__DIR__ . '/includes/poll_throttle.php')) {
  require_once __DIR__ . '/includes/poll_throttle.php';
  poll_throttle('comments_poll', 180);
}

/**
 * Автообновление комментариев канала (вкладка открыта).
 * GET channel_id, after (comment id)
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$channelId = (int)($_GET['channel_id'] ?? 0);
$after = (int)($_GET['after'] ?? 0);
if ($channelId <= 0) {
  echo json_encode(['ok' => false, 'comments' => []]);
  exit;
}

try {
  $st = db()->prepare(
    'SELECT cm.id, cm.message, cm.created_at, u.username, u.is_verified
     FROM comments cm JOIN users u ON u.id = cm.user_id
     WHERE cm.channel_id = ? AND cm.is_deleted = 0 AND cm.id > ?
     ORDER BY cm.id ASC LIMIT 50'
  );
  $st->execute([$channelId, $after]);
  $rows = $st->fetchAll();
  echo json_encode(['ok' => true, 'comments' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'comments' => []]);
}
