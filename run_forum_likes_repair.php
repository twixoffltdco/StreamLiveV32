<?php
/**
 * Ремонт лайков после переноса БД:
 * 1) удаляет лайки на несуществующие/удалённые посты
 * 2) пересчитывает forum_posts.like_count из COUNT(*)
 * 3) создаёт таблицы если нет
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/functions.php';
if (is_file(__DIR__ . '/includes/forum_engine.php')) {
  require_once __DIR__ . '/includes/forum_engine.php';
  if (function_exists('forum_engine_ensure')) forum_engine_ensure();
}

$pdo = db();
echo "=== forum likes repair ===\n";

// orphan likes
$orphans = 0;
try {
  $orphans = (int)$pdo->exec(
    'DELETE l FROM forum_post_likes l
     LEFT JOIN forum_posts p ON p.id = l.post_id
     WHERE p.id IS NULL OR COALESCE(p.is_deleted,0) = 1'
  );
} catch (Throwable $e) {
  echo "orphan delete: " . $e->getMessage() . "\n";
}
echo "orphans_removed=" . $orphans . "\n";

// recalculate all
$updated = 0;
try {
  $pdo->exec('UPDATE forum_posts SET like_count = 0');
  $rows = $pdo->query(
    'SELECT post_id, COUNT(*) AS c FROM forum_post_likes GROUP BY post_id'
  )->fetchAll();
  $st = $pdo->prepare('UPDATE forum_posts SET like_count = ? WHERE id = ?');
  foreach ($rows as $r) {
    $st->execute([(int)$r['c'], (int)$r['post_id']]);
    $updated++;
  }
} catch (Throwable $e) {
  echo "recalc: " . $e->getMessage() . "\n";
}
echo "posts_with_likes_updated=" . $updated . "\n";

// stats
try {
  $likes = (int)$pdo->query('SELECT COUNT(*) FROM forum_post_likes')->fetchColumn();
  $posts = (int)$pdo->query('SELECT COUNT(*) FROM forum_posts')->fetchColumn();
  $nonzero = (int)$pdo->query('SELECT COUNT(*) FROM forum_posts WHERE like_count > 0')->fetchColumn();
  echo "likes_rows=$likes posts=$posts posts_with_like_count_gt0=$nonzero\n";
} catch (Throwable $e) {
  echo $e->getMessage() . "\n";
}

// moderation schema soft
if (is_file(__DIR__ . '/includes/content_moderation.php')) {
  require_once __DIR__ . '/includes/content_moderation.php';
  if (function_exists('cmod_ensure_schema')) {
    try { cmod_ensure_schema(); echo "moderation_schema=ok\n"; } catch (Throwable $e) {
      echo "moderation: " . $e->getMessage() . "\n";
    }
  }
}

echo "DONE\n";
