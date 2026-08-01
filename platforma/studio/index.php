<?php
declare(strict_types=1);
$studio_title = 'Панель студии';
$studio_active = 'dashboard';

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$user = current_user();
$user_id = $user ? (int)$user['id'] : 0;

$views = 0;
$videoViews = 0;
$videoCount = 0;
$live = 0;
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
    $videoCount = count($recentVideos);
    // total count + views
    $st2 = db()->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(views_count),0) AS v FROM videos WHERE user_id = ?");
    $st2->execute([$user_id]);
    $agg = $st2->fetch() ?: [];
    $videoCount = (int)($agg['c'] ?? $videoCount);
    $videoViews = (int)($agg['v'] ?? 0);
  } catch (Throwable $e) {
    try {
      $st = db()->prepare("SELECT id, title, slug, status FROM videos WHERE user_id = ? ORDER BY id DESC LIMIT 15");
      $st->execute([$user_id]);
      $recentVideos = $st->fetchAll() ?: [];
      $videoCount = count($recentVideos);
    } catch (Throwable $e2) {}
  }
}

require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Панель управления каналом</h1>
<p class="st-sub">Стиль YouTube Studio · ваши данные из StreamLife</p>

<?php if ($user_id <= 0): ?>
<div class="alert alert-info">Войдите в аккаунт StreamLife, чтобы видеть статистику.</div>
<a class="btn btn-blue" href="/auth/login.php?next=<?= rawurlencode('/platforma/studio/') ?>">Войти</a>
<?php else: ?>

<div class="st-cards">
  <div class="st-card"><div class="lbl">Просмотры каналов</div><div class="val"><?= number_format($views) ?></div></div>
  <div class="st-card"><div class="lbl">Просмотры видео</div><div class="val"><?= number_format($videoViews) ?></div></div>
  <div class="st-card"><div class="lbl">ТВ</div><div class="val"><?= count($channelsTv) ?></div></div>
  <div class="st-card"><div class="lbl">Радио</div><div class="val"><?= count($channelsRadio) ?></div></div>
  <div class="st-card"><div class="lbl">Ваши видео</div><div class="val"><?= number_format($videoCount) ?></div></div>
</div>

<div class="st-panel">
  <h2>Быстрые действия</h2>
  <div class="st-actions">
    <a class="btn btn-blue" href="/platforma/studio/content.php">Весь контент</a>
    <a class="btn btn-white" href="/platforma/studio/import.php">Импорт видео</a>
    <a class="btn btn-outline" href="/platforma/studio/analytics.php">Аналитика</a>
    <a class="btn btn-outline" href="/platforma/studio/channel.php">Оформление</a>
    <a class="btn btn-outline" href="/new_channel.php">Новый канал</a>
  </div>
</div>

<div class="st-panel">
  <h2>ТВ-каналы (<?= count($channelsTv) ?>)</h2>
  <?php if (!$channelsTv): ?>
    <p class="muted">ТВ пока нет. <a href="/new_channel.php">Создать</a></p>
  <?php else: ?>
  <table class="st-table">
    <thead><tr><th>Канал</th><th>Статус</th><th>Просмотры</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($channelsTv as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['title']) ?></td>
        <td class="muted"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></td>
        <td><?= number_format((int)($r['views'] ?? 0)) ?></td>
        <td>
          <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel.php?id=<?= (int)$r['id'] ?>">Открыть</a>
          <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel_manage.php?id=<?= (int)$r['id'] ?>">Настройки</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="st-panel">
  <h2>Радио (<?= count($channelsRadio) ?>)</h2>
  <?php if (!$channelsRadio): ?>
    <p class="muted">Радио пока нет. <a href="/new_channel.php">Создать</a></p>
  <?php else: ?>
  <table class="st-table">
    <thead><tr><th>Канал</th><th>Статус</th><th>Просмотры</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($channelsRadio as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['title']) ?></td>
        <td class="muted"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></td>
        <td><?= number_format((int)($r['views'] ?? 0)) ?></td>
        <td>
          <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel.php?id=<?= (int)$r['id'] ?>">Открыть</a>
          <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel_manage.php?id=<?= (int)$r['id'] ?>">Настройки</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="st-panel">
  <h2>Последние видео (<?= $videoCount ?>)</h2>
  <?php if (!$recentVideos): ?>
    <p class="muted">Видео пока нет. <a href="/platforma/studio/import.php">Импорт</a></p>
  <?php else: ?>
  <table class="st-table">
    <thead><tr><th></th><th>Название</th><th>Статус</th><th>Просмотры</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recentVideos as $v): ?>
      <tr>
        <td><?php if (!empty($v['thumbnail_url'])): ?><img src="<?= htmlspecialchars($v['thumbnail_url']) ?>" alt="" style="width:64px;height:36px;object-fit:cover;border-radius:4px;background:#222"><?php endif; ?></td>
        <td><?= htmlspecialchars(mb_substr((string)($v['title'] ?? ''), 0, 60)) ?></td>
        <td class="muted"><?= htmlspecialchars((string)($v['status'] ?? '')) ?></td>
        <td><?= number_format((int)($v['views_count'] ?? 0)) ?></td>
        <td>
          <?php if (!empty($v['slug'])): ?>
            <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/video/<?= htmlspecialchars($v['slug']) ?>">Открыть</a>
          <?php else: ?>
            <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/video.php?id=<?= (int)$v['id'] ?>">Открыть</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="st-actions" style="margin-top:12px">
    <a class="btn btn-outline" href="/platforma/studio/content.php">Все видео и каналы →</a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
