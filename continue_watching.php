<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

$pageTitle = 'Продолжить просмотр';
require_once __DIR__ . '/includes/header.php';

$stmt = db()->prepare(
  "SELECT v.*, c.title AS channel_title, wp.position_seconds, wp.duration_seconds FROM video_watch_progress wp
   JOIN videos v ON v.id = wp.video_id JOIN channels c ON c.id = v.channel_id
   WHERE wp.user_id = ? AND v.status = 'published' ORDER BY wp.updated_at DESC LIMIT 40"
);
$stmt->execute([$__user['id']]);
$videos = $stmt->fetchAll();
?>
<div class="container">
  <h1>▶️ Продолжить просмотр</h1>
  <?php if (!$videos): ?><div class="empty-state"><p>Пока нечего продолжать — работает для .mp4/.m3u8 видео (для YouTube/VK и т.п. это недоступно, у их плееров нет открытого доступа к позиции просмотра со стороннего сайта).</p></div><?php endif; ?>
  <div class="video-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px">
    <?php foreach ($videos as $v): $pct = $v['duration_seconds'] ? min(100, round($v['position_seconds'] / $v['duration_seconds'] * 100)) : 0; ?>
      <a href="/video.php?slug=<?= e($v['slug']) ?>&resume=1" style="text-decoration:none;color:inherit">
        <div style="position:relative;aspect-ratio:16/9;background:#111 url('<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>') center/cover;border-radius:10px;overflow:hidden">
          <div style="position:absolute;bottom:0;left:0;right:0;height:4px;background:rgba(255,255,255,.25)"><div style="height:100%;width:<?= $pct ?>%;background:var(--accent-2)"></div></div>
        </div>
        <div style="padding:8px 2px">
          <b style="display:block;font-size:13px"><?= e(mb_substr($v['title'], 0, 55)) ?></b>
          <span style="font-size:12px;color:var(--text-dim)"><?= e($v['channel_title']) ?> · осталось ~<?= max(0, round((($v['duration_seconds'] ?: 0) - $v['position_seconds']) / 60)) ?> мин</span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
