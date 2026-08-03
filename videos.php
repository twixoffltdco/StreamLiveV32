<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__ . '/includes/recommendations.php')) require_once __DIR__ . '/includes/recommendations.php';

$q = trim((string)($_GET['q'] ?? ''));
$pageTitle = $q !== '' ? 'Поиск: ' . $q : 'Видео';
require_once __DIR__ . '/includes/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

if ($q !== '') {
  $stmt = db()->prepare(
    "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug FROM videos v
     JOIN channels c ON c.id = v.channel_id
     WHERE v.status = 'published' AND MATCH(v.title, v.description, v.tags) AGAINST (? IN NATURAL LANGUAGE MODE)
     ORDER BY v.created_at DESC LIMIT ? OFFSET ?"
  );
  $stmt->bindValue(1, $q);
  $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(3, $offset, PDO::PARAM_INT);
  $stmt->execute();
} else {
  $stmt = db()->prepare(
    "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug FROM videos v
     JOIN channels c ON c.id = v.channel_id
     WHERE v.status = 'published' ORDER BY v.created_at DESC LIMIT ? OFFSET ?"
  );
  $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
  $stmt->bindValue(2, $offset, PDO::PARAM_INT);
  $stmt->execute();
}
$videos = $stmt->fetchAll();
?>
<div class="container">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:20px 0">
    <h1 style="margin:0">📺 Видео</h1>
    <form method="GET" style="display:flex;gap:6px">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск видео..." style="padding:9px;width:200px;max-width:60vw">
      <button class="btn btn-outline btn-sm" type="submit">Найти</button>
    </form>
  </div>

  <?php if (!$videos): ?>
    <div class="empty-state">
      <p>Видео пока нет<?= $q !== '' ? ' по запросу «' . e($q) . '»' : '' ?>.</p>
      <?php if (current_user()): ?><p>Если у вас есть канал — <a href="/dashboard.php" style="color:var(--accent-2)">откройте его и импортируйте первое видео</a>.</p><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="video-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px">
    <?php foreach ($videos as $v): ?>
      <a href="/video.php?slug=<?= e($v['slug']) ?>" style="text-decoration:none;color:inherit">
        <div style="aspect-ratio:16/9;background:#111 url('<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>') center/cover;border-radius:10px"></div>
        <div style="padding:8px 2px">
          <b style="display:block;font-size:13px;line-height:1.3"><?= e(mb_substr($v['title'], 0, 70)) ?></b>
          <span style="font-size:12px;color:var(--text-dim)"><?= e($v['channel_title']) ?> · <?= (int)$v['views_count'] ?> просм.</span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:20px;display:flex;gap:8px">
    <?php if ($page > 1): ?><a class="btn btn-outline btn-sm" href="?q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>">← Назад</a><?php endif; ?>
    <?php if (count($videos) === $perPage): ?><a class="btn btn-outline btn-sm" href="?q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>">Далее →</a><?php endif; ?>
  </div>
</div>
<?php if (function_exists('render_recommendations_section')): ?>
<div class="container">
  <?php render_recommendations_section('videos', 8); ?>
  <?php render_recommendations_section('tv', 6); ?>
  <?php render_recommendations_section('radio', 6); ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
