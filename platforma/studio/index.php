<?php
declare(strict_types=1);
$studio_title = 'Панель студии';
$studio_active = 'dashboard';

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$user = function_exists('current_user') ? current_user() : null;
$user_id = $user ? (int)$user['id'] : 0;
if ($user_id <= 0 && !empty($_SESSION['user_id'])) {
  $user_id = (int)$_SESSION['user_id'];
}

$views = 0;
$videoViews = 0;
$videoCount = 0;
$favCount = 0;
$channelsTv = [];
$channelsRadio = [];
$recentVideos = [];

if ($user_id > 0) {
  try {
    $chRows = [];
    try {
      $st = db()->prepare("SELECT id, title, slug, type, status, views FROM channels WHERE owner_id = ? ORDER BY id DESC LIMIT 50");
      $st->execute([$user_id]);
      $chRows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {}
    if (!$chRows) {
      try {
        $st = db()->prepare("SELECT id, title, slug, type, status, views FROM channels WHERE user_id = ? ORDER BY id DESC LIMIT 50");
        $st->execute([$user_id]);
        $chRows = $st->fetchAll() ?: [];
      } catch (Throwable $e) {}
    }
    foreach ($chRows as $row) {
      $views += (int)($row['views'] ?? 0);
      $tt = strtolower(trim((string)($row['type'] ?? 'tv')));
      if ($tt === 'radio' || strpos($tt, 'radio') !== false) $channelsRadio[] = $row;
      else $channelsTv[] = $row;
    }
  } catch (Throwable $e) {}

  try {
    $st = db()->prepare(
      "SELECT id, title, slug, views_count, status, thumbnail_url, created_at
       FROM videos WHERE user_id = ? ORDER BY id DESC LIMIT 15"
    );
    $st->execute([$user_id]);
    $recentVideos = $st->fetchAll() ?: [];
    $st2 = db()->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(views_count),0) AS v FROM videos WHERE user_id = ?");
    $st2->execute([$user_id]);
    $agg = $st2->fetch() ?: [];
    $videoCount = (int)($agg['c'] ?? count($recentVideos));
    $videoViews = (int)($agg['v'] ?? 0);
  } catch (Throwable $e) {
    try {
      $st = db()->prepare("SELECT id, title, slug, status FROM videos WHERE user_id = ? ORDER BY id DESC LIMIT 15");
      $st->execute([$user_id]);
      $recentVideos = $st->fetchAll() ?: [];
      $videoCount = count($recentVideos);
    } catch (Throwable $e2) {}
  }

  try {
    $st = db()->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?");
    $st->execute([$user_id]);
    $favCount = (int)$st->fetchColumn();
  } catch (Throwable $e) {}
}

require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Студия Платформы</h1>
<p class="st-sub">Управление каналами, видео, импортом и аналитикой</p>

<?php if ($user_id <= 0): ?>
<div class="alert alert-info">Войдите, чтобы видеть статистику и управлять контентом.</div>
<a class="btn btn-blue" href="/auth/login.php?next=<?= rawurlencode('/platforma/studio/') ?>">Войти</a>
<?php else: ?>

<div class="st-cards">
  <div class="st-card"><div class="lbl">Просмотры каналов</div><div class="val"><?= number_format($views) ?></div></div>
  <div class="st-card"><div class="lbl">Просмотры видео</div><div class="val"><?= number_format($videoViews) ?></div></div>
  <div class="st-card"><div class="lbl">Видео</div><div class="val"><?= (int)$videoCount ?></div></div>
  <div class="st-card"><div class="lbl">ТВ-каналы</div><div class="val"><?= count($channelsTv) ?></div></div>
  <div class="st-card"><div class="lbl">Радио</div><div class="val"><?= count($channelsRadio) ?></div></div>
  <div class="st-card"><div class="lbl">Избранное</div><div class="val"><?= (int)$favCount ?></div></div>
</div>

<div class="st-panel">
  <h2>Быстрые действия</h2>
  <div class="st-actions">
    <a class="btn btn-red" href="/platforma/studio/import.php">＋ Импорт видео</a>
    <a class="btn btn-blue" href="/platforma/studio/content.php">Мой контент</a>
    <a class="btn btn-white" href="/platforma/studio/channel.php">Оформление канала</a>
    <a class="btn btn-outline" href="/platforma/studio/analytics.php">Аналитика</a>
    <a class="btn btn-outline" href="/new_channel.php">Новый канал</a>
    <a class="btn btn-outline" href="/favorites">Избранное</a>
    <a class="btn btn-outline" href="/videos">Каталог видео</a>
  </div>
</div>

<div class="st-panel">
  <h2>Импорт с внешних сайтов</h2>
  <p class="muted">Dropbox, Instagram и любые страницы с плеером — через <code>embedwebsite</code> + ваш прокси.</p>
  <div class="st-actions" style="margin-top:10px">
    <a class="btn btn-blue" href="/platforma/studio/import.php">Открыть импорт</a>
    <a class="btn btn-outline" href="/embedwebsite/index.php" target="_blank">Тест плеера embedwebsite</a>
  </div>
</div>

<?php if ($channelsTv || $channelsRadio): ?>
<div class="st-panel">
  <h2>Ваши каналы</h2>
  <table class="st-table">
    <thead><tr><th>Название</th><th>Тип</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_merge($channelsTv, $channelsRadio) as $ch): ?>
      <tr>
        <td><?= htmlspecialchars($ch['title'] ?? '') ?></td>
        <td class="muted"><?= htmlspecialchars($ch['type'] ?? 'tv') ?></td>
        <td class="muted"><?= htmlspecialchars($ch['status'] ?? '') ?></td>
        <td><a href="/channel.php?slug=<?= urlencode($ch['slug'] ?? '') ?>">Открыть</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($recentVideos): ?>
<div class="st-panel">
  <h2>Недавние видео</h2>
  <table class="st-table">
    <thead><tr><th>Название</th><th>Просмотры</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recentVideos as $v): ?>
      <tr>
        <td><?= htmlspecialchars($v['title'] ?? '') ?></td>
        <td class="muted"><?= (int)($v['views_count'] ?? 0) ?></td>
        <td class="muted"><?= htmlspecialchars($v['status'] ?? '') ?></td>
        <td>
          <?php if (!empty($v['slug'])): ?>
            <a href="/video.php?slug=<?= urlencode($v['slug']) ?>">Смотреть</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="st-panel">
  <h2>Видео пока нет</h2>
  <p class="muted">Импортируйте первое видео с Dropbox / Instagram / любого сайта с плеером.</p>
  <a class="btn btn-red" href="/platforma/studio/import.php">Импортировать</a>
</div>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__ . '/_layout_end.php'; ?>
