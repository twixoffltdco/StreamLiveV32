<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user_display.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['posts' => []]); exit; }

$channelId = (int)($_GET['channel_id'] ?? 0);
$after = (int)($_GET['after'] ?? 0);

$stmt = db()->prepare(
  "SELECT bp.id, bp.body, u.id AS user_id, u.username, u.avatar, u.is_verified, u.is_banned,
          u.username_css, u.prefix_id, u.custom_prefix_id, u.nick_decor_url, u.nick_decor_pos, u.gravatar_email
   FROM broadcast_posts bp JOIN users u ON u.id = bp.author_id
   WHERE bp.channel_id = ? AND bp.id > ? AND bp.is_deleted = 0 ORDER BY bp.id ASC LIMIT 50"
);
try {
  $stmt->execute([$channelId, $after]);
  $rows = $stmt->fetchAll();
} catch (Throwable $e) {
  $stmt = db()->prepare(
    "SELECT bp.id, bp.body, u.id AS user_id, u.username FROM broadcast_posts bp JOIN users u ON u.id = bp.author_id
     WHERE bp.channel_id = ? AND bp.id > ? AND bp.is_deleted = 0 ORDER BY bp.id ASC LIMIT 50"
  );
  $stmt->execute([$channelId, $after]);
  $rows = $stmt->fetchAll();
}

if ($rows) {
  $viewStmt = db()->prepare('INSERT IGNORE INTO broadcast_post_views (post_id, user_id) VALUES (?, ?)');
  foreach ($rows as $r) { $viewStmt->execute([$r['id'], $user['id']]); }
}

$viewCountStmt = db()->prepare('SELECT COUNT(*) FROM broadcast_post_views WHERE post_id = ?');
$commentCountStmt = db()->prepare('SELECT COUNT(*) FROM broadcast_post_comments WHERE post_id = ?');
foreach ($rows as &$r) {
  $viewCountStmt->execute([$r['id']]);
  $r['views_count'] = (int)$viewCountStmt->fetchColumn();
  $commentCountStmt->execute([$r['id']]);
  $r['comments_count'] = (int)$commentCountStmt->fetchColumn();
  $author = $r;
  $author['id'] = (int)($r['user_id'] ?? 0);
  $r['username_html'] = user_badge_html_compact($author, 22);
}
unset($r);

echo json_encode(['posts' => array_map(function ($r) {
  return [
    'id' => (int)$r['id'],
    'username' => $r['username'],
    'username_html' => $r['username_html'] ?? $r['username'],
    'body' => $r['body'],
    'views_count' => $r['views_count'],
    'comments_count' => $r['comments_count'],
  ];
}, $rows)], JSON_UNESCAPED_UNICODE);
