<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notify.php';
header('Content-Type: application/json; charset=utf-8');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false, 'error' => 'login']); exit; }
$input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$channelId = (int)($input['channel_id'] ?? 0);
$action = (string)($input['action'] ?? 'subscribe');
if ($channelId <= 0) { echo json_encode(['ok' => false]); exit; }
notify_ensure_schema();
try {
  if ($action === 'unsubscribe') {
    db()->prepare('DELETE FROM channel_subscriptions WHERE user_id = ? AND channel_id = ?')
      ->execute([(int)$user['id'], $channelId]);
    echo json_encode(['ok' => true, 'subscribed' => false]);
  } else {
    db()->prepare('INSERT IGNORE INTO channel_subscriptions (user_id, channel_id) VALUES (?, ?)')
      ->execute([(int)$user['id'], $channelId]);
    echo json_encode(['ok' => true, 'subscribed' => true]);
  }
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'db']);
}
