<?php
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
csrf_verify();

$commentId = (int)($_POST['comment_id'] ?? 0);
$channelId = (int)($_POST['channel_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM channels WHERE id = ?');
$stmt->execute([$channelId]);
$channel = $stmt->fetch();

if ($channel && is_channel_moderator($channelId, $user['id'])) {
  $stmt = db()->prepare('SELECT user_id FROM comments WHERE id = ? AND channel_id = ?');
  $stmt->execute([$commentId, $channelId]);
  $comment = $stmt->fetch();

  if ($comment) {
    db()->prepare('UPDATE comments SET is_deleted = 1 WHERE id = ? AND channel_id = ?')->execute([$commentId, $channelId]);

    // Автора удалённого комментария блокируем на канале целиком (чат + сама страница канала).
    // Владельца канала (если он вдруг удаляет свой же комментарий) не баним.
    if ((int)$comment['user_id'] !== (int)$channel['owner_id']) {
      db()->prepare('INSERT IGNORE INTO chat_bans (channel_id, user_id) VALUES (?, ?)')->execute([$channelId, $comment['user_id']]);
    }
  }
}

redirect('/channel.php?slug=' . ($channel['slug'] ?? '') . '#comments');
