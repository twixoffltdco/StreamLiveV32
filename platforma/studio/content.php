<?php
declare(strict_types=1);
/**
 * Контент студии: видео пользователя + ТВ + радио (без заглушек).
 */
$studio_title = 'Контент';
$studio_active = 'content';

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$user = current_user();
if (!$user) {
  header('Location: /auth/login.php?next=' . rawurlencode('/platforma/studio/content.php'));
  exit;
}
$uid = (int)$user['id'];

$videos = [];
$channelsTv = [];
$channelsRadio = [];

try {
  $st = db()->prepare(
    "SELECT id, title, slug, status, views_count, likes_count, comments_count, thumbnail_url, created_at, platform
     FROM videos WHERE user_id = ? ORDER BY id DESC LIMIT 100"
  );
  $st->execute([$uid]);
  $videos = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $st = db()->prepare("SELECT id, title, slug, status, created_at FROM videos WHERE user_id = ? ORDER BY id DESC LIMIT 100");
    $st->execute([$uid]);
    $videos = $st->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}

try {
  $chRows = [];
  try {
    $st = db()->prepare(
      "SELECT id, title, slug, type, status, views, created_at FROM channels
       WHERE owner_id = ? ORDER BY id DESC LIMIT 100"
    );
    $st->execute([$uid]);
    $chRows = $st->fetchAll() ?: [];
  } catch (Throwable $e) {}
  if (!$chRows) {
    try {
      $st = db()->prepare(
        "SELECT id, title, slug, type, status, views, created_at FROM channels
         WHERE user_id = ? ORDER BY id DESC LIMIT 100"
      );
      $st->execute([$uid]);
      $chRows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {}
  }
  foreach ($chRows as $c) {
    $tt = strtolower(trim((string)($c['type'] ?? 'tv')));
    if ($tt === 'radio' || strpos($tt, 'radio') !== false) $channelsRadio[] = $c;
    else $channelsTv[] = $c;
  }
} catch (Throwable $e) {}

require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Контент канала</h1>
<p class="st-sub">Ваши видео, ТВ и радио · без заглушек</p>

<div class="st-cards">
  <div class="st-card"><div class="lbl">Видео</div><div class="val"><?= count($videos) ?></div></div>
  <div class="st-card"><div class="lbl">ТВ-каналы</div><div class="val"><?= count($channelsTv) ?></div></div>
  <div class="st-card"><div class="lbl">Радио</div><div class="val"><?= count($channelsRadio) ?></div></div>
</div>

<div class="st-panel">
  <h2>Видео (<?= count($videos) ?>)</h2>
  <?php if (!$videos): ?>
    <p class="muted">Пока нет видео. Импортируйте или добавьте через канал.</p>
    <div class="st-actions">
      <a class="btn btn-blue" href="/platforma/studio/import.php">Импорт</a>
      <a class="btn btn-outline" href="/dashboard.php">Dashboard</a>
    </div>
  <?php else: ?>
  <table class="st-table">
    <thead><tr><th></th><th>Название</th><th>Статус</th><th>Просмотры</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($videos as $v): ?>
      <tr>
        <td><?php if (!empty($v['thumbnail_url'])): ?><img src="<?= htmlspecialchars($v['thumbnail_url']) ?>" alt="" style="width:72px;height:40px;object-fit:cover;border-radius:4px;background:#222"><?php endif; ?></td>
        <td><?= htmlspecialchars(mb_substr((string)($v['title'] ?? ''), 0, 70)) ?></td>
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
  <?php endif; ?>
</div>

<div class="st-panel">
  <h2>ТВ-каналы (<?= count($channelsTv) ?>)</h2>
  <?php if (!$channelsTv): ?>
    <p class="muted">ТВ-каналов пока нет.</p>
    <a class="btn btn-blue" href="/new_channel.php">Создать канал</a>
  <?php else: ?>
  <table class="st-table">
    <thead><tr><th>Название</th><th>Статус</th><th>Просмотры</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($channelsTv as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['title']) ?></td>
        <td class="muted"><?= htmlspecialchars((string)($c['status'] ?? '')) ?></td>
        <td><?= number_format((int)($c['views'] ?? 0)) ?></td>
        <td>
          <?php if (!empty($c['slug'])): ?>
            <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel/<?= htmlspecialchars($c['slug']) ?>">Открыть</a>
          <?php else: ?>
            <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel.php?id=<?= (int)$c['id'] ?>">Открыть</a>
          <?php endif; ?>
          <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel_manage.php?id=<?= (int)$c['id'] ?>">Настройки</a>
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
    <p class="muted">Радио-каналов пока нет.</p>
    <a class="btn btn-blue" href="/new_channel.php">Создать радио</a>
  <?php else: ?>
  <table class="st-table">
    <thead><tr><th>Название</th><th>Статус</th><th>Просмотры</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($channelsRadio as $c): ?>
      <tr>
        <td><?= htmlspecialchars($c['title']) ?></td>
        <td class="muted"><?= htmlspecialchars((string)($c['status'] ?? '')) ?></td>
        <td><?= number_format((int)($c['views'] ?? 0)) ?></td>
        <td>
          <?php if (!empty($c['slug'])): ?>
            <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel/<?= htmlspecialchars($c['slug']) ?>">Открыть</a>
          <?php else: ?>
            <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel.php?id=<?= (int)$c['id'] ?>">Открыть</a>
          <?php endif; ?>
          <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel_manage.php?id=<?= (int)$c['id'] ?>">Настройки</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>
