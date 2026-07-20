<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

$user = current_user();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$channelId = (int)($input['channel_id'] ?? 0);
$messageId = (int)($input['message_id'] ?? 0);

if (!$user || !is_channel_moderator($channelId, $user['id'])) {
  echo json_encode(['ok' => false, 'error' => 'Нет прав']);
  exit;
}

db()->prepare('UPDATE chat_messages SET is_deleted = 1 WHERE id = ? AND channel_id = ?')->execute([$messageId, $channelId]);
echo json_encode(['ok' => true]);
