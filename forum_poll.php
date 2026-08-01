<?php
/**
 * Live «Что нового» для форума (как XenForo / open tab).
 * GET: after_ts=UNIX (или after=ISO), category_id=0|N, limit=20
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$afterTs = (int)($_GET['after_ts'] ?? 0);
$after = trim((string)($_GET['after'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$limit = max(1, min(40, (int)($_GET['limit'] ?? 15)));

if ($afterTs <= 0 && $after !== '') {
  $afterTs = (int)strtotime($after);
}
if ($afterTs <= 0) {
  $afterTs = time() - 3600; // первый запрос — час назад
}

$params = [date('Y-m-d H:i:s', $afterTs)];
$sql = "SELECT t.id, t.title, t.category_id, t.created_at, t.last_post_at, t.is_pinned,
               u.username, fc.title AS category_title,
               (SELECT COUNT(*) FROM forum_posts p WHERE p.thread_id = t.id AND p.is_deleted = 0) AS post_count
        FROM forum_threads t
        JOIN users u ON u.id = t.user_id
        JOIN forum_categories fc ON fc.id = t.category_id
        WHERE t.is_deleted = 0
          AND (t.created_at > ? OR t.last_post_at > ?)";
$params[] = date('Y-m-d H:i:s', $afterTs);

if ($categoryId > 0) {
  $sql .= " AND t.category_id = ?";
  $params[] = $categoryId;
}
$sql .= " ORDER BY GREATEST(t.created_at, IFNULL(t.last_post_at, t.created_at)) DESC LIMIT " . (int)$limit;

try {
  $st = db()->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'db', 'threads' => [], 'server_ts' => time()]);
  exit;
}

$threads = [];
$maxTs = $afterTs;
foreach ($rows as $r) {
  $cAt = strtotime($r['created_at'] ?? '') ?: 0;
  $lAt = strtotime($r['last_post_at'] ?? '') ?: 0;
  $ts = max($cAt, $lAt);
  if ($ts > $maxTs) $maxTs = $ts;
  $threads[] = [
    'id' => (int)$r['id'],
    'title' => $r['title'],
    'category_id' => (int)$r['category_id'],
    'category_title' => $r['category_title'],
    'username' => $r['username'],
    'created_at' => $r['created_at'],
    'last_post_at' => $r['last_post_at'],
    'post_count' => (int)$r['post_count'],
    'is_pinned' => (int)$r['is_pinned'],
    'is_new_thread' => $cAt > $afterTs,
  ];
}

echo json_encode([
  'ok' => true,
  'threads' => $threads,
  'server_ts' => time(),
  'after_ts' => $maxTs > $afterTs ? $maxTs : $afterTs,
], JSON_UNESCAPED_UNICODE);
