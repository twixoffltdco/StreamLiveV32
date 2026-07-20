<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user = current_user();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$code = strtoupper(trim((string)($input['room_code'] ?? '')));
$message = trim(mb_substr((string)($input['message'] ?? ''), 0, 500));
if ($message === '') { echo json_encode(['ok' => false]); exit; }

$stmt = db()->prepare('SELECT id FROM watch_rooms WHERE room_code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) { echo json_encode(['ok' => false]); exit; }

// Гость без аккаунта тоже может писать в чат комнаты — это разговор друзей по ссылке,
// не требует регистрации, только имя из сессии/куки на один визит
if ($user) {
  db()->prepare('INSERT INTO watch_room_messages (room_id, user_id, message) VALUES (?, ?, ?)')->execute([$room['id'], $user['id'], $message]);
} else {
  if (empty($_COOKIE['guest_name'])) {
    setcookie('guest_name', 'Гость' . random_int(1000, 9999), time() + 86400, '/');
    $guestName = $_COOKIE['guest_name'] ?? 'Гость';
  } else {
    $guestName = $_COOKIE['guest_name'];
  }
  db()->prepare('INSERT INTO watch_room_messages (room_id, guest_name, message) VALUES (?, ?, ?)')->execute([$room['id'], $guestName, $message]);
}

echo json_encode(['ok' => true]);
