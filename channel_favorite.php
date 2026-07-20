<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false, 'error' => 'Нужно войти']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$channelId = (int)($input['channel_id'] ?? 0);

$stmt = db()->prepare('SELECT id FROM favorites WHERE channel_id = ? AND user_id = ?');
$stmt->execute([$channelId, $user['id']]);
if ($stmt->fetch()) {
  db()->prepare('DELETE FROM favorites WHERE channel_id = ? AND user_id = ?')->execute([$channelId, $user['id']]);
  $fav = false;
} else {
  db()->prepare('INSERT IGNORE INTO favorites (channel_id, user_id) VALUES (?, ?)')->execute([$channelId, $user['id']]);
  $fav = true;
}
echo json_encode(['ok' => true, 'favorited' => $fav]);
