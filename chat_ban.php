<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

$user = current_user();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$channelId = (int)($input['channel_id'] ?? 0);
$targetUserId = (int)($input['user_id'] ?? 0);

if (!$user || !is_channel_moderator($channelId, $user['id'])) {
  echo json_encode(['ok' => false, 'error' => 'Нет прав']);
  exit;
}

$stmt = db()->prepare('INSERT IGNORE INTO chat_bans (channel_id, user_id) VALUES (?, ?)');
$stmt->execute([$channelId, $targetUserId]);
echo json_encode(['ok' => true]);
