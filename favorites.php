<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

$pageTitle = 'Избранное';
require_once __DIR__ . '/includes/header.php';

$stmt = db()->prepare(
  "SELECT v.*, c.title AS channel_title FROM video_favorites f
   JOIN videos v ON v.id = f.video_id JOIN channels c ON c.id = v.channel_id
   WHERE f.user_id = ? AND v.status = 'published' ORDER BY f.created_at DESC"
);
$stmt->execute([$__user['id']]);
$videos = $stmt->fetchAll();
?>
<div class="container">
  <h1>⭐ Избранное</h1>
  <?php if (!$videos): ?>
    <div class="empty-state"><p>Пока пусто. Жмите ⭐ на видео, чтобы добавить сюда.</p></div>
  <?php endif; ?>
  <div class="video-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px">
    <?php foreach ($videos as $v): ?>
      <a href="/video.php?slug=<?= e($v['slug']) ?>" style="text-decoration:none;color:inherit">
        <div style="aspect-ratio:16/9;background:#111 url('<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>') center/cover;border-radius:10px"></div>
        <div style="padding:8px 2px">
          <b style="display:block;font-size:13px"><?= e(mb_substr($v['title'], 0, 60)) ?></b>
          <span style="font-size:12px;color:var(--text-dim)"><?= e($v['channel_title']) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
