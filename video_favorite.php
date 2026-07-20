<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false, 'error' => 'Нужно войти']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$videoId = (int)($input['video_id'] ?? 0);

$stmt = db()->prepare('SELECT id FROM video_favorites WHERE video_id = ? AND user_id = ?');
$stmt->execute([$videoId, $user['id']]);
if ($stmt->fetch()) {
  db()->prepare('DELETE FROM video_favorites WHERE video_id = ? AND user_id = ?')->execute([$videoId, $user['id']]);
  $fav = false;
} else {
  db()->prepare('INSERT IGNORE INTO video_favorites (video_id, user_id) VALUES (?, ?)')->execute([$videoId, $user['id']]);
  $fav = true;
}
echo json_encode(['ok' => true, 'favorited' => $fav]);
