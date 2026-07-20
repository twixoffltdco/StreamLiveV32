<?php
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();
csrf_verify();

$channelId = (int)($_POST['channel_id'] ?? 0);
$message = trim(mb_substr($_POST['message'] ?? '', 0, 1000));

$stmt = db()->prepare("SELECT slug FROM channels WHERE id = ? AND status = 'approved'");
$stmt->execute([$channelId]);
$channel = $stmt->fetch();

if ($channel && $message !== '') {
  $stmt = db()->prepare('INSERT INTO comments (channel_id, user_id, message) VALUES (?, ?, ?)');
  $stmt->execute([$channelId, $__user['id'], $message]);
}

redirect('/channel.php?slug=' . ($channel['slug'] ?? '') . '#comments');
