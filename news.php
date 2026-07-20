<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = current_user();

$pageTitle = 'Новости';
require_once __DIR__ . '/includes/header.php';

$channelId = (int)($_GET['channel_id'] ?? 0);
$sql = "SELECT ri.*, rs.title AS source_title, rs.channel_id, c.title AS channel_title, c.slug AS channel_slug
        FROM rss_items ri
        JOIN rss_sources rs ON rs.id = ri.source_id
        LEFT JOIN channels c ON c.id = rs.channel_id";
$params = [];
if ($channelId) { $sql .= ' WHERE rs.channel_id = ?'; $params[] = $channelId; }
$sql .= ' ORDER BY COALESCE(ri.published_at, ri.created_at) DESC LIMIT 100';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();
?>
<div class="container">
  <h2 style="margin:24px 0 16px">Новости</h2>
  <?php if (empty($items)): ?>
    <div class="empty-state">Пока новостей нет — админ ещё не добавил RSS-источники или их не обновляли.</div>
  <?php else: ?>
  <div class="forum-thread-list">
    <?php foreach ($items as $it): ?>
      <div class="forum-thread-row" style="flex-direction:column;align-items:flex-start;gap:6px">
        <a href="<?= e($it['link']) ?>" target="_blank" rel="noopener noreferrer nofollow" class="forum-thread-title"><?= e($it['title']) ?></a>
        <div style="color:var(--text-dim);font-size:12px">
          <?= e($it['source_title']) ?><?php if ($it['channel_title']): ?> · <a href="/channel.php?slug=<?= e($it['channel_slug']) ?>" style="color:var(--accent-2)"><?= e($it['channel_title']) ?></a><?php endif; ?>
          <?php if ($it['published_at']): ?> · <?= e($it['published_at']) ?><?php endif; ?>
        </div>
        <?php if ($it['description']): ?><p style="color:var(--text-dim);font-size:13px;margin:0"><?= e(mb_substr(strip_tags($it['description']), 0, 240)) ?>…</p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
