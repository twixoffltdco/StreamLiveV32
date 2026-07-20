<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$after = (int)($_GET['after'] ?? 0);

$stmt = db()->prepare('SELECT id FROM watch_rooms WHERE room_code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) { echo json_encode(['ok' => false]); exit; }

$stmt = db()->prepare(
  "SELECT m.id, m.message, COALESCE(u.username, m.guest_name, 'Гость') AS author
   FROM watch_room_messages m LEFT JOIN users u ON u.id = m.user_id
   WHERE m.room_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 50"
);
$stmt->execute([$room['id'], $after]);
echo json_encode(['ok' => true, 'messages' => $stmt->fetchAll()]);
