<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/poll_throttle.php';
if (!poll_throttle_check('chat_poll', 40, 60)) { exit; }
header('Content-Type: application/json');

$channelId = (int)($_GET['channel_id'] ?? 0);
$after = (int)($_GET['after'] ?? 0);

$stmt = db()->prepare(
  'SELECT cm.id, cm.user_id, u.username, cm.message, cm.is_deleted, cm.created_at
   FROM chat_messages cm JOIN users u ON u.id = cm.user_id
   WHERE cm.channel_id = ? AND cm.id > ? ORDER BY cm.id ASC LIMIT 50'
);
$stmt->execute([$channelId, $after]);
$rows = $stmt->fetchAll();

$messages = [];
$deleted = [];
foreach ($rows as $r) {
  if ($r['is_deleted']) { $deleted[] = (int)$r['id']; continue; }
  $messages[] = [
    'id' => (int)$r['id'], 'userId' => (int)$r['user_id'], 'username' => $r['username'],
    'message' => $r['message'], 'messageHtml' => render_with_stickers($r['message']),
  ];
}

// Также проверим, не были ли удалены/забанены сообщения ниже after (на случай позднего удаления модератором)
$stmt = db()->prepare('SELECT id FROM chat_messages WHERE channel_id = ? AND is_deleted = 1 AND id <= ?');
$stmt->execute([$channelId, $after]);
foreach ($stmt->fetchAll() as $r) { $deleted[] = (int)$r['id']; }

echo json_encode(['messages' => $messages, 'deleted' => array_values(array_unique($deleted))]);
