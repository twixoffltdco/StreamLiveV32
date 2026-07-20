<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false]); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$code = strtoupper(trim((string)($input['room_code'] ?? '')));

$stmt = db()->prepare('SELECT id FROM watch_rooms WHERE room_code = ? AND host_user_id = ?');
$stmt->execute([$code, $user['id']]);
$room = $stmt->fetch();
if (!$room) { echo json_encode(['ok' => false, 'error' => 'Не хост этой комнаты']); exit; }

db()->prepare('UPDATE watch_rooms SET is_playing = ?, position_seconds = ?, state_updated_at = NOW() WHERE id = ?')
  ->execute([!empty($input['is_playing']) ? 1 : 0, (float)($input['position'] ?? 0), $room['id']]);

echo json_encode(['ok' => true]);
