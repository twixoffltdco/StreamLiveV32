<?php
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();
csrf_verify();
if (function_exists('sl_rate_limit') && !sl_rate_limit('comment_add', 5, (int)$__user['id'])) {
  flash_set('error', 'Слишком часто. Подождите несколько секунд.');
  redirect($_SERVER['HTTP_REFERER'] ?? '/');
}

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
