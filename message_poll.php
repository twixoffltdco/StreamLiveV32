<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/contacts.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
  http_response_code(401);
  echo json_encode(['messages' => []]);
  exit;
}

$convId = (int)($_GET['conversation_id'] ?? 0);
$after = (int)($_GET['after'] ?? 0);

try {
  $stmt = db()->prepare('SELECT id, user_a_id, user_b_id FROM conversations WHERE id = ? AND (user_a_id = ? OR user_b_id = ?)');
  $stmt->execute([$convId, $user['id'], $user['id']]);
  $conv = $stmt->fetch();
  if (!$conv) {
    echo json_encode(['messages' => []]);
    exit;
  }

  $peerId = ((int)$conv['user_a_id'] === (int)$user['id'])
    ? (int)$conv['user_b_id']
    : (int)$conv['user_a_id'];

  if (contacts_is_blocked_either((int)$user['id'], $peerId)) {
    echo json_encode(['messages' => [], 'blocked' => true]);
    exit;
  }

  $stmt = db()->prepare(
    'SELECT id, sender_id, body FROM messages WHERE conversation_id = ? AND id > ? AND is_deleted = 0 ORDER BY id ASC LIMIT 100'
  );
  $stmt->execute([$convId, $after]);
  $rows = $stmt->fetchAll();

  $incomingIds = [];
  foreach ($rows as $r) {
    if ((int)$r['sender_id'] !== (int)$user['id']) $incomingIds[] = (int)$r['id'];
  }
  if ($incomingIds) {
    $in = implode(',', array_fill(0, count($incomingIds), '?'));
    db()->prepare("UPDATE messages SET read_at = NOW() WHERE id IN ($in) AND read_at IS NULL")->execute($incomingIds);
  }

  $messages = array_map(static function ($r) {
    return ['id' => (int)$r['id'], 'senderId' => (int)$r['sender_id'], 'body' => $r['body']];
  }, $rows);

  echo json_encode(['messages' => $messages]);
} catch (Throwable $e) {
  echo json_encode(['messages' => []]);
}
