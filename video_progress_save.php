<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false]); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$videoId = (int)($input['video_id'] ?? 0);
$position = max(0, (int)($input['position'] ?? 0));
$duration = isset($input['duration']) ? max(0, (int)$input['duration']) : null;

// Досмотрел до конца (> 95%) — считаем просмотренным, чистим прогресс, чтобы не висело в "продолжить"
if ($duration && $position >= $duration * 0.95) {
  db()->prepare('DELETE FROM video_watch_progress WHERE user_id = ? AND video_id = ?')->execute([$user['id'], $videoId]);
  echo json_encode(['ok' => true, 'completed' => true]);
  exit;
}

db()->prepare(
  'INSERT INTO video_watch_progress (user_id, video_id, position_seconds, duration_seconds) VALUES (?, ?, ?, ?)
   ON DUPLICATE KEY UPDATE position_seconds = VALUES(position_seconds), duration_seconds = VALUES(duration_seconds)'
)->execute([$user['id'], $videoId, $position, $duration]);

echo json_encode(['ok' => true]);
