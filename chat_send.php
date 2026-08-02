<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user) { echo json_encode(['ok' => false, 'error' => 'Нужно войти в аккаунт']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$channelId = (int)($input['channel_id'] ?? 0);
$message = trim(mb_substr($input['message'] ?? '', 0, 500));

if ($message === '') { echo json_encode(['ok' => false, 'error' => 'Пустое сообщение']); exit; }

if (function_exists('sl_rate_limit') && !sl_rate_limit('chat_send', 2, (int)$user['id'])) {
  echo json_encode(['ok' => false, 'error' => 'Слишком быстро. Подождите пару секунд.']); exit;
}

$stmt = db()->prepare('SELECT id FROM chat_bans WHERE channel_id = ? AND user_id = ?');
$stmt->execute([$channelId, $user['id']]);
if ($stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Вы забанены в этом чате']); exit; }

try {
  $stmt = db()->prepare('INSERT INTO chat_messages (channel_id, user_id, message) VALUES (?, ?, ?)');
  $stmt->execute([$channelId, $user['id'], $message]);
  echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId()]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Ошибка сохранения сообщения. Если это повторяется — проверьте кодировку таблиц БД (см. sql/fix_charset.sql)']);
}
