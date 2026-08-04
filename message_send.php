<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/contacts.php';
require_once __DIR__ . '/includes/notify.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Войдите']);
  exit;
}

contacts_ensure_schema();
contacts_maybe_unban((int)$user['id']);

// если сам забанен на платформе — не пишем
try {
  $st = db()->prepare('SELECT is_banned FROM users WHERE id = ?');
  $st->execute([(int)$user['id']]);
  if ((int)$st->fetchColumn() === 1) {
    echo json_encode(['ok' => false, 'error' => 'Ваш аккаунт заблокирован на платформе']);
    exit;
  }
} catch (Throwable $e) {}

$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
$convId = (int)($input['conversation_id'] ?? 0);
$body = trim(mb_substr((string)($input['body'] ?? ''), 0, 2000));

if ($body === '' || !$convId) {
  echo json_encode(['ok' => false, 'error' => 'Пустое сообщение']);
  exit;
}

try {
  $stmt = db()->prepare('SELECT id, user_a_id, user_b_id FROM conversations WHERE id = ? AND (user_a_id = ? OR user_b_id = ?)');
  $stmt->execute([$convId, $user['id'], $user['id']]);
  $conv = $stmt->fetch();
  if (!$conv) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Диалог не найден']);
    exit;
  }

  $peerId = ((int)$conv['user_a_id'] === (int)$user['id'])
    ? (int)$conv['user_b_id']
    : (int)$conv['user_a_id'];

  if (contacts_is_blocked_either((int)$user['id'], $peerId)) {
    echo json_encode(['ok' => false, 'error' => 'Переписка недоступна: пользователь в чёрном списке']);
    exit;
  }

  db()->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)')
    ->execute([$convId, $user['id'], $body]);
  $id = (int)db()->lastInsertId();
  db()->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]);
  // Push / in-app уведомление собеседнику
  $preview = mb_substr($body, 0, 80);
  $uname = (string)($user['username'] ?? 'Пользователь');
  notify_user(
    $peerId,
    'message',
    $uname . ': ' . $preview,
    '/messages?conv=' . $convId,
    null,
    (int)$user['id']
  );
  echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok' => false, 'error' => 'Не удалось отправить сообщение']);
}
