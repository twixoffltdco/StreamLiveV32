<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/poll_throttle.php';
if (!poll_throttle_check('wr_poll', 24, 60)) { exit; }
header('Content-Type: application/json; charset=utf-8');

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$stmt = db()->prepare('SELECT is_playing, position_seconds FROM watch_rooms WHERE room_code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) { echo json_encode(['ok' => false]); exit; }

echo json_encode(['ok' => true, 'is_playing' => (bool)$room['is_playing'], 'position' => (float)$room['position_seconds']]);
