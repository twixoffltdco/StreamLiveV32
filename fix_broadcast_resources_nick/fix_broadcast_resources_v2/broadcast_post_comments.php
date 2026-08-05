<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user_display.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Нужно войти']); exit; }

$postId = (int)($_GET['post_id'] ?? 0);

try {
  $stmt = db()->prepare(
    "SELECT bpc.id, bpc.body, bpc.created_at, u.id AS user_id, u.username, u.avatar, u.is_verified, u.is_banned,
            u.username_css, u.prefix_id, u.custom_prefix_id, u.nick_decor_url, u.nick_decor_pos, u.gravatar_email
     FROM broadcast_post_comments bpc JOIN users u ON u.id = bpc.user_id
     WHERE bpc.post_id = ? ORDER BY bpc.id ASC LIMIT 500"
  );
  $stmt->execute([$postId]);
  $rows = $stmt->fetchAll();
} catch (Throwable $e) {
  $stmt = db()->prepare(
    "SELECT bpc.id, bpc.body, bpc.created_at, u.id AS user_id, u.username, u.avatar, u.is_verified, u.is_banned
     FROM broadcast_post_comments bpc JOIN users u ON u.id = bpc.user_id
     WHERE bpc.post_id = ? ORDER BY bpc.id ASC LIMIT 500"
  );
  $stmt->execute([$postId]);
  $rows = $stmt->fetchAll();
}

echo json_encode(['ok' => true, 'comments' => array_map(function ($r) {
  $author = $r;
  $author['id'] = (int)($r['user_id'] ?? 0);
  return [
    'id' => (int)$r['id'],
    'username' => $r['username'],
    'username_html' => user_badge_html_compact($author, 18),
    'avatar' => $r['avatar'],
    'is_verified' => (bool)$r['is_verified'],
    'is_banned' => (bool)$r['is_banned'],
    'body' => $r['body'],
    'created_at' => $r['created_at'],
  ];
}, $rows)], JSON_UNESCAPED_UNICODE);
