<?php
declare(strict_types=1);
$studio_title = 'Панель';
$studio_active = 'dashboard';
$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
$user = current_user();
if (!$user) { header('Location: /login.php'); exit; }
$uid = (int)$user['id'];
$videoCount = $videoViews = $favCount = $chCount = 0;
try {
  $st = db()->prepare('SELECT COUNT(*) c, COALESCE(SUM(views_count),0) v FROM videos WHERE user_id = ?');
  $st->execute([$uid]);
  $r = $st->fetch() ?: [];
  $videoCount = (int)($r['c'] ?? 0);
  $videoViews = (int)($r['v'] ?? 0);
} catch (Throwable $e) {}
try {
  $st = db()->prepare('SELECT COUNT(*) FROM channels WHERE owner_id = ?');
  $st->execute([$uid]);
  $chCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}
try {
  $st = db()->prepare(
    'SELECT COUNT(*) FROM favorites f JOIN channels c ON c.id = f.channel_id WHERE c.owner_id = ?'
  );
  $st->execute([$uid]);
  $favCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}
require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Панель студии</h1>
<p class="st-sub">Обзор каналов и видео · единый стиль Platforma Studio</p>
<div class="st-cards">
  <div class="st-card"><div class="lbl">Каналы</div><div class="val"><?= $chCount ?></div></div>
  <div class="st-card"><div class="lbl">Видео</div><div class="val"><?= $videoCount ?></div></div>
  <div class="st-card"><div class="lbl">Просмотры видео</div><div class="val"><?= $videoViews ?></div></div>
  <div class="st-card"><div class="lbl">В избранном у зрителей</div><div class="val"><?= $favCount ?></div></div>
</div>
<div class="st-panel">
  <h2>Быстрые действия</h2>
  <div class="st-actions">
    <a class="btn btn-primary" href="/platforma/studio/import.php">Импорт видео</a>
    <a class="btn btn-outline" href="/platforma/studio/content.php">Контент</a>
    <a class="btn btn-outline" href="/platforma/studio/subscribers.php">Подписчики</a>
    <a class="btn btn-outline" href="/platforma/studio/analytics.php">Аналитика</a>
  </div>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>
