<?php
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();
csrf_verify();

$videoId = (int)($_POST['video_id'] ?? 0);
$message = trim(mb_substr($_POST['message'] ?? '', 0, 1000));

$stmt = db()->prepare("SELECT slug FROM videos WHERE id = ?");
$stmt->execute([$videoId]);
$video = $stmt->fetch();

if ($video && $message !== '') {
  db()->prepare('INSERT INTO video_comments (video_id, user_id, message) VALUES (?, ?, ?)')->execute([$videoId, $__user['id'], $message]);
  db()->prepare('UPDATE videos SET comments_count = comments_count + 1 WHERE id = ?')->execute([$videoId]);
}

redirect('/video.php?slug=' . ($video['slug'] ?? '') . '#comments');
