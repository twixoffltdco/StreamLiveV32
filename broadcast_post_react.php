<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Нужно войти']); exit; }

// Фиксированный набор — как в Telegram, не произвольный emoji-picker, чтобы не тащить
// огромный emoji-пикер в JS ради этого. Достаточно для 95% случаев.
const BROADCAST_REACTIONS = ['👍', '👎', '❤️', '🔥', '🎉', '😁', '😢', '🤔'];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$postId = (int)($input['post_id'] ?? 0);
$emoji = (string)($input['emoji'] ?? '');

if (!in_array($emoji, BROADCAST_REACTIONS, true)) {
  echo json_encode(['ok' => false, 'error' => 'Недопустимая реакция']); exit;
}

$stmt = db()->prepare('SELECT id FROM broadcast_posts WHERE id = ? AND is_deleted = 0');
$stmt->execute([$postId]);
if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Пост не найден']); exit; }

$stmt = db()->prepare('SELECT emoji FROM broadcast_post_reactions WHERE post_id = ? AND user_id = ?');
$stmt->execute([$postId, $user['id']]);
$existing = $stmt->fetchColumn();

if ($existing === $emoji) {
  // Повторный клик на ту же реакцию — снимаем (как в Telegram).
  db()->prepare('DELETE FROM broadcast_post_reactions WHERE post_id = ? AND user_id = ?')->execute([$postId, $user['id']]);
  $myReaction = null;
} else {
  // Есть другая реакция — заменяем; нет вообще — ставим новую.
  db()->prepare('REPLACE INTO broadcast_post_reactions (post_id, user_id, emoji) VALUES (?, ?, ?)')
    ->execute([$postId, $user['id'], $emoji]);
  $myReaction = $emoji;
}

$stmt = db()->prepare('SELECT emoji, COUNT(*) AS cnt FROM broadcast_post_reactions WHERE post_id = ? GROUP BY emoji');
$stmt->execute([$postId]);
$counts = [];
foreach ($stmt->fetchAll() as $row) { $counts[$row['emoji']] = (int)$row['cnt']; }

echo json_encode(['ok' => true, 'my_reaction' => $myReaction, 'counts' => $counts]);
