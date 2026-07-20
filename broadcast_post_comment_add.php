<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Нужно войти']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$postId = (int)($input['post_id'] ?? 0);
$body = trim(mb_substr((string)($input['body'] ?? ''), 0, 1000));

if ($body === '' || !$postId) { echo json_encode(['ok' => false, 'error' => 'Пустой комментарий']); exit; }

$stmt = db()->prepare('SELECT id FROM broadcast_posts WHERE id = ? AND is_deleted = 0');
$stmt->execute([$postId]);
if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Пост не найден']); exit; }

try {
  db()->prepare('INSERT INTO broadcast_post_comments (post_id, user_id, body) VALUES (?, ?, ?)')
    ->execute([$postId, $user['id'], $body]);
  $id = (int)db()->lastInsertId();
  echo json_encode([
    'ok' => true, 'id' => $id, 'username' => $user['username'],
    'avatar' => $user['avatar'] ?? null,
    'is_verified' => (bool)($user['is_verified'] ?? false),
  ]);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Не удалось отправить комментарий']);
}
