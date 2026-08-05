<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user_display.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Нужно войти']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$channelId = (int)($input['channel_id'] ?? 0);
$body = trim(mb_substr((string)($input['body'] ?? ''), 0, 4000));

if ($body === '' || !$channelId) { echo json_encode(['ok' => false, 'error' => 'Пустой пост']); exit; }

$stmt = db()->prepare('SELECT owner_id FROM broadcast_channels WHERE id = ?');
$stmt->execute([$channelId]);
$ownerId = $stmt->fetchColumn();

if ((int)$ownerId !== (int)$user['id']) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Публиковать может только владелец канала']);
  exit;
}

try {
  db()->prepare('INSERT INTO broadcast_posts (channel_id, author_id, body) VALUES (?, ?, ?)')
    ->execute([$channelId, $user['id'], $body]);
  $id = (int)db()->lastInsertId();
  echo json_encode([
    'ok' => true,
    'id' => $id,
    'username' => $user['username'],
    'username_html' => user_badge_html_compact($user, 22),
  ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Не удалось сохранить пост']);
}
