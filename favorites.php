<?php
/**
 * Избранное / Подписки — в стиле YouTube Subscriptions (режим Платформа).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();
$uid = (int)$__user['id'];

$pageTitle = 'Подписки';
$extraHead = ($extraHead ?? '') . '<link rel="stylesheet" href="/assets/css/youtube-watch.css?v=7">';
require_once __DIR__ . '/includes/header.php';

$plMode = $_COOKIE['pl_ui_mode'] ?? ($_SESSION['pl_ui_mode'] ?? 'streamlife');
$isPl = ($plMode === 'platforma');

// Каналы в избранном
$channels = [];
try {
  $st = db()->prepare(
    "SELECT c.*, f.created_at AS fav_at
     FROM favorites f
     JOIN channels c ON c.id = f.channel_id
     WHERE f.user_id = ?
     ORDER BY f.created_at DESC
     LIMIT 100"
  );
  $st->execute([$uid]);
  $channels = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $st = db()->prepare(
      "SELECT c.* FROM channel_favorites f JOIN channels c ON c.id = f.channel_id WHERE f.user_id = ? ORDER BY f.id DESC LIMIT 100"
    );
    $st->execute([$uid]);
    $channels = $st->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}

// Видео в избранном
$videos = [];
try {
  $st = db()->prepare(
    "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug
     FROM video_favorites f
     JOIN videos v ON v.id = f.video_id
     JOIN channels c ON c.id = v.channel_id
     WHERE f.user_id = ? AND v.status = 'published'
     ORDER BY f.created_at DESC
     LIMIT 48"
  );
  $st->execute([$uid]);
  $videos = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

// Видео с каналов из избранного (лента подписок)
$feed = [];
if ($channels) {
  $ids = array_map(static fn($c) => (int)$c['id'], $channels);
  $ids = array_values(array_filter($ids));
  if ($ids) {
    try {
      $in = implode(',', array_fill(0, count($ids), '?'));
      $st = db()->prepare(
        "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug
         FROM videos v
         JOIN channels c ON c.id = v.channel_id
         WHERE v.channel_id IN ($in) AND v.status = 'published'
         ORDER BY v.created_at DESC
         LIMIT 36"
      );
      $st->execute($ids);
      $feed = $st->fetchAll() ?: [];
    } catch (Throwable $e) {}
  }
}
?>
<style>
.pl-subs-page { max-width: 1400px; margin: 0 auto; padding: 12px 16px 48px; }
.pl-subs-title { font-size: 22px; font-weight: 600; margin: 8px 0 16px; }
.pl-subs-row {
  display: flex; gap: 16px; overflow-x: auto; padding: 8px 0 20px;
  scrollbar-width: thin;
}
.pl-subs-ch {
  flex: 0 0 auto; width: 88px; text-align: center; text-decoration: none; color: inherit;
}
.pl-subs-ch img {
  width: 72px; height: 72px; border-radius: 50%; object-fit: cover;
  background: #272727; display: block; margin: 0 auto 6px;
  border: 2px solid transparent;
}
.pl-subs-ch:hover img { border-color: var(--pl-accent, #f00); }
.pl-subs-ch span {
  display: block; font-size: 12px; line-height: 1.3;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.pl-subs-sec { font-size: 16px; font-weight: 600; margin: 8px 0 12px; }
.pl-subs-empty { color: var(--pl-muted, #aaa); padding: 24px 0; font-size: 14px; }
body.pl-theme-platforma .pl-subs-page { color: var(--pl-text); }
</style>

<div class="container pl-subs-page">
  <h1 class="pl-subs-title"><?= $isPl ? 'Подписки' : '⭐ Избранное' ?></h1>

  <?php if ($channels): ?>
    <div class="pl-subs-sec">Каналы</div>
    <div class="pl-subs-row">
      <?php foreach ($channels as $ch):
        $slug = $ch['slug'] ?? '';
        $title = $ch['title'] ?? ('Канал #' . ($ch['id'] ?? ''));
        $ava = $ch['avatar_url'] ?? $ch['logo_url'] ?? $ch['image'] ?? '/assets/img/avatar-placeholder.png';
        $href = $slug !== '' ? '/channel.php?slug=' . e($slug) : '/channel.php?id=' . (int)($ch['id'] ?? 0);
      ?>
        <a class="pl-subs-ch" href="<?= $href ?>" title="<?= e($title) ?>">
          <img src="<?= e($ava) ?>" alt="" loading="lazy"
            onerror="this.src='/assets/img/avatar-placeholder.png'">
          <span><?= e(mb_substr($title, 0, 18)) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="pl-subs-empty">Нет избранных каналов. На странице канала нажмите «В избранное».</p>
  <?php endif; ?>

  <?php if ($feed): ?>
    <div class="pl-subs-sec">С подписок</div>
    <div class="yt-grid">
      <?php foreach ($feed as $v):
        $thumb = $v['thumbnail_url'] ?: '/assets/img/video-placeholder.png';
      ?>
        <a class="yt-card" href="/video.php?slug=<?= e($v['slug']) ?>">
          <img class="yt-card-thumb" src="<?= e($thumb) ?>" alt="" loading="lazy"
            onerror="this.src='/assets/img/video-placeholder.png'">
          <div class="yt-card-title"><?= e($v['title']) ?></div>
          <div class="yt-card-meta"><?= e($v['channel_title'] ?? '') ?> · <?= (int)($v['views_count'] ?? 0) ?> просмотров</div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($videos): ?>
    <div class="pl-subs-sec" style="margin-top:28px">Избранные видео</div>
    <div class="yt-grid">
      <?php foreach ($videos as $v):
        $thumb = $v['thumbnail_url'] ?: '/assets/img/video-placeholder.png';
      ?>
        <a class="yt-card" href="/video.php?slug=<?= e($v['slug']) ?>">
          <img class="yt-card-thumb" src="<?= e($thumb) ?>" alt="" loading="lazy"
            onerror="this.src='/assets/img/video-placeholder.png'">
          <div class="yt-card-title"><?= e($v['title']) ?></div>
          <div class="yt-card-meta"><?= e($v['channel_title'] ?? '') ?></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php elseif (!$feed): ?>
    <p class="pl-subs-empty">Избранных видео пока нет — жмите ⭐ на странице ролика.</p>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
