<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__ . '/includes/recommendations.php')) require_once __DIR__ . '/includes/recommendations.php';

$q = trim((string)($_GET['q'] ?? ''));
$pageTitle = $q !== '' ? 'Поиск: ' . $q : 'Видео';
$extraHead = ($extraHead ?? '') . '<link rel="stylesheet" href="/assets/css/youtube-watch.css?v=1">';
require_once __DIR__ . '/includes/themes.php';
$__th = themes_active();
$__platformStyle = false;
if ($__th && stripos((string)($__th['slug'] ?? ''), 'platform') !== false) $__platformStyle = true;
if (!$__platformStyle && !empty($_COOKIE['site_theme']) && stripos((string)$_COOKIE['site_theme'], 'platform') !== false) $__platformStyle = true;
if (!$__platformStyle && !empty($_COOKIE['sl_style']) && stripos((string)$_COOKIE['sl_style'], 'platform') !== false) $__platformStyle = true;
if ($__platformStyle) {
  $extraHead = ($extraHead ?? '') . '<link rel="stylesheet" href="/assets/css/platform-videos.css?v=2">'
    . '<link rel="stylesheet" href="/assets/css/platform-youtube-shell.css?v=3">';
}
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

  <?php if (!empty($__platformStyle)): ?>
  <div class="platform-videos-shell">
    <aside class="platform-videos-side">
      <a href="/videos">📺 Все видео</a>
      <a href="/forum_whats_new">🔥 Что нового</a>
      <a href="/channels">📡 Каналы</a>
      <?php if (current_user()): ?><a href="/platforma/studio/">🎛 Студия</a><?php endif; ?>
    </aside>
    <div class="platform-videos-main">
      <div class="platform-videos-tabs">
        <a class="active" href="/videos">Все</a>
        <a href="/videos?q=музыка">Музыка</a>
        <a href="/videos?q=новости">Новости</a>
        <a href="/videos?q=спорт">Спорт</a>
      </div>
      <div class="platform-videos-grid">
        <?php foreach ($videos as $v): ?>
          <a class="platform-video-card" href="/video.php?slug=<?= e($v['slug']) ?>">
            <div class="platform-video-thumb" style="background-image:url('<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>')"></div>
            <div class="platform-video-title"><?= e($v['title']) ?></div>
            <div class="platform-video-meta"><?= e($v['channel_title']) ?> · <?= (int)$v['views_count'] ?> просмотров</div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php else: ?>
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
  <?php endif; ?>

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
