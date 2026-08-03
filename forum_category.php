<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__.'/includes/user_display.php')) { require_once __DIR__.'/includes/user_display.php'; try{user_display_ensure_schema();}catch(Throwable $e){} }
require_once __DIR__ . '/includes/service_helpers.php';
$__user = current_user();

$categoryId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM forum_categories WHERE id = ?');
$stmt->execute([$categoryId]);
$category = $stmt->fetch();

if (!$category) {
  http_response_code(404);
  $pageTitle = 'Категория не найдена';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Категория не найдена</h2><a href="/forum.php" class="btn btn-primary" style="margin-top:14px">На форум</a></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$pageTitle = $category['title'] . ' — Форум';
require_once __DIR__ . '/includes/header.php';

$stmt = db()->prepare(
  "SELECT t.*, u.username, u.avatar, u.is_verified, u.is_banned, u.gravatar_email, u.prefix_id, u.username_css,
     (SELECT COUNT(*) FROM forum_posts p WHERE p.thread_id = t.id AND p.is_deleted = 0) AS post_count
   FROM forum_threads t JOIN users u ON u.id = t.user_id
   WHERE t.category_id = ? AND t.is_deleted = 0
   ORDER BY t.is_pinned DESC, t.last_post_at DESC LIMIT 200"
);
$stmt->execute([$categoryId]);
$threads = $stmt->fetchAll();
?>
<div class="container">
  <p style="margin:20px 0 4px"><a href="/forum.php" style="color:var(--accent-2);font-size:13px">← Форум</a></p>
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <h1 style="margin:0 0 4px">📁 <?= e($category['title']) ?></h1>
    <?php if ($__user): ?><a href="/forum_new_thread.php?category_id=<?= (int)$categoryId ?>" class="btn btn-primary btn-sm">+ Новая тема</a><?php endif; ?>
  </div>
  <?php if ($category['description']): ?><p style="color:var(--text-dim);font-size:13px;margin-bottom:20px"><?= e($category['description']) ?></p><?php endif; ?>

  <div class="forum-thread-list">
    <?php foreach ($threads as $t): ?>
      <div class="forum-thread-row">
        <div class="forum-thread-main">
          <?php if ($t['is_pinned']): ?><span class="pin-badge">Закреплено</span><?php endif; ?>
          <?php if ($t['is_locked']): ?><span class="lock-badge">Закрыто</span><?php endif; ?>
          <a href="/forum_thread.php?id=<?= (int)$t['id'] ?>" class="forum-thread-title"><?= e($t['title']) ?></a>
          <div style="color:var(--text-dim);font-size:12px;display:flex;align-items:center;gap:6px;margin-top:2px">
            <?= render_user_badge($t, 18) ?> · <?= e($t['created_at']) ?>
            <?= banned_user_notice($t) ?>
          </div>
        </div>
        <div class="forum-thread-stats">
          <div><b><?= (int)$t['post_count'] ?></b><span>ответов</span></div>
          <div><b><?= (int)$t['views'] ?></b><span>просмотров</span></div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($threads)): ?>
      <div class="empty-state"><h2>Тем пока нет</h2><p>Будьте первым, кто начнёт обсуждение.</p></div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
