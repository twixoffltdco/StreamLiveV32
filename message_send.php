<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user) { echo json_encode(['ok' => false, 'error' => 'Нужно войти в аккаунт']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$convId = (int)($input['conversation_id'] ?? 0);
$body = trim(mb_substr((string)($input['body'] ?? ''), 0, 2000));

if ($body === '' || !$convId) { echo json_encode(['ok' => false, 'error' => 'Пустое сообщение']); exit; }

// Проверяем, что пользователь реально состоит в этом диалоге — иначе можно было бы
// писать в чужие переписки, просто подобрав conversation_id
$stmt = db()->prepare('SELECT id FROM conversations WHERE id = ? AND (user_a_id = ? OR user_b_id = ?)');
$stmt->execute([$convId, $user['id'], $user['id']]);
if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Диалог не найден']); exit; }

try {
  db()->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)')
    ->execute([$convId, $user['id'], $body]);
  $id = (int)db()->lastInsertId();
  db()->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);
  echo json_encode(['ok' => true, 'id' => $id]);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Не удалось отправить сообщение']);
}
