<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = current_user();

$pageTitle = 'Форум';
require_once __DIR__ . '/includes/header.php';

$categories = db()->query(
  "SELECT fc.*,
     (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = fc.id AND t.is_deleted = 0) AS thread_count,
     (SELECT COUNT(*) FROM forum_posts p JOIN forum_threads t ON t.id = p.thread_id
       WHERE t.category_id = fc.id AND t.is_deleted = 0 AND p.is_deleted = 0) AS post_count
   FROM forum_categories fc ORDER BY fc.sort_order ASC, fc.id ASC"
)->fetchAll();

foreach ($categories as &$cat) {
  $stmt = db()->prepare(
    "SELECT t.id, t.title, t.last_post_at, u.username FROM forum_threads t
     JOIN users u ON u.id = t.user_id
     WHERE t.category_id = ? AND t.is_deleted = 0 ORDER BY t.last_post_at DESC LIMIT 1"
  );
  $stmt->execute([$cat['id']]);
  $cat['last_thread'] = $stmt->fetch();
}
unset($cat);
?>
<div class="container">
  <div style="display:flex;align-items:center;justify-content:space-between;margin:24px 0 6px;flex-wrap:wrap;gap:10px">
    <div>
    <h1 style="margin:0">Форум</h1>
    <div class="forum-tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 0">
      <a href="/forum.php" class="btn btn-primary btn-sm">Категории</a>
      <a href="/forum_whats_new.php" class="btn btn-outline btn-sm">Что нового</a>
    </div>
  </div>
    <div style="display:flex;gap:8px">
      <button type="button" class="btn btn-outline btn-sm" onclick="copyRssLink('<?= e(SITE_URL) ?>/rss_forum.php')">📋 RSS-ссылка</button>
      <?php if ($__user && $__user['role'] === 'admin'): ?>
        <a href="/admin/forum.php" class="btn btn-outline btn-sm">Управление категориями</a>
      <?php endif; ?>
    </div>
  </div>
  <p style="color:var(--text-dim);font-size:13px;margin-bottom:12px">Обсуждайте что угодно, используйте BBCode ([b], [i], [url], [img], [quote], [code], [spoiler] и т.д.)</p>
  <script>
    function copyRssLink(url) {
      navigator.clipboard.writeText(url).then(() => alert('Ссылка на RSS скопирована! Вставьте её, например, в настройки автопостинга группы ВК.'));
    }
  </script>

  
  <div id="forum-live-banner" class="forum-live-banner" style="display:none;cursor:pointer;margin:0 0 12px;padding:10px 14px;border-radius:10px;background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.35);font-size:13px"></div>
  <div id="forum-whats-new-list" class="forum-thread-list" style="margin-bottom:18px"></div>
  <div id="forum-live-root" data-category-id="0" data-after-ts="<?= time() ?>"></div>
  <script src="/assets/js/forum-live.js?v=1" defer></script>

  <div class="forum-cat-list">
    <?php foreach ($categories as $cat): ?>
      <div class="forum-cat-card">
        <div class="forum-cat-main">
          <a href="/forum_category.php?id=<?= (int)$cat['id'] ?>" class="forum-cat-title"><?= e($cat['title']) ?></a>
          <?php if ($cat['description']): ?><p class="forum-cat-desc"><?= e($cat['description']) ?></p><?php endif; ?>
        </div>
        <div class="forum-cat-stats">
          <div><b><?= (int)$cat['thread_count'] ?></b><span>тем</span></div>
          <div><b><?= (int)$cat['post_count'] ?></b><span>сообщений</span></div>
        </div>
        <div class="forum-cat-last">
          <?php if ($cat['last_thread']): ?>
            <a href="/forum_thread.php?id=<?= (int)$cat['last_thread']['id'] ?>"><?= e($cat['last_thread']['title']) ?></a>
            <span style="color:var(--text-dim)">от <?= e($cat['last_thread']['username']) ?> · <?= e($cat['last_thread']['last_post_at']) ?></span>
          <?php else: ?>
            <span style="color:var(--text-dim)">Пока нет тем</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($categories)): ?>
      <div class="empty-state"><h2>Категорий пока нет</h2><p>Администратор ещё не создал ни одной категории форума.</p></div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
