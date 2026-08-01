<?php
declare(strict_types=1);
/**
 * Детальная аналитика канала (YouTube Studio–style).
 * Работает и в режиме Платформа, и в Telegram.
 */
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$user = current_user();
if (!$user) {
  header('Location: /auth/login.php');
  exit;
}
$uid = (int)$user['id'];
$days = (int)($_GET['days'] ?? 28);
if (!in_array($days, [7, 28, 90, 365], true)) $days = 28;
$since = date('Y-m-d H:i:s', time() - $days * 86400);
$sinceDay = date('Y-m-d', time() - $days * 86400);

// Каналы пользователя
$channels = [];
try {
  $st = db()->prepare("SELECT id, title, slug, type, status, views FROM channels WHERE owner_id = ? ORDER BY id DESC");
  $st->execute([$uid]);
  $channels = $st->fetchAll() ?: [];
} catch (Throwable $e) {}
$channelIds = array_map(fn($c) => (int)$c['id'], $channels);
$chIn = $channelIds ? implode(',', $channelIds) : '0';

// Видео
$videos = [];
$totalVideoViews = 0;
$totalLikes = 0;
$totalComments = 0;
try {
  $st = db()->prepare(
    "SELECT id, title, slug, views_count, likes_count, comments_count, status, created_at, thumbnail_url
     FROM videos WHERE user_id = ? ORDER BY views_count DESC LIMIT 50"
  );
  $st->execute([$uid]);
  $videos = $st->fetchAll() ?: [];
  foreach ($videos as $v) {
    $totalVideoViews += (int)($v['views_count'] ?? 0);
    $totalLikes += (int)($v['likes_count'] ?? 0);
    $totalComments += (int)($v['comments_count'] ?? 0);
  }
} catch (Throwable $e) {
  try {
    $st = db()->prepare("SELECT id, title, slug, views_count, status, created_at FROM videos WHERE user_id = ? ORDER BY id DESC LIMIT 50");
    $st->execute([$uid]);
    $videos = $st->fetchAll() ?: [];
    foreach ($videos as $v) $totalVideoViews += (int)($v['views_count'] ?? 0);
  } catch (Throwable $e2) {}
}

$channelViews = 0;
foreach ($channels as $c) $channelViews += (int)($c['views'] ?? 0);

// Просмотры страниц профиля / видео / каналов за период (page_views)
$pathViews = [];
$daily = [];
$topPaths = [];
try {
  // пути, связанные с пользователем
  $uname = (string)$user['username'];
  $st = db()->prepare(
    "SELECT path, COUNT(*) AS c FROM page_views
     WHERE created_at >= ? AND (
       path LIKE ? OR path LIKE ? OR path LIKE ?
     )
     GROUP BY path ORDER BY c DESC LIMIT 20"
  );
  $st->execute([$since, '%/profile%' . $uname . '%', '%/u/' . $uname . '%', '%/video%']);
  $topPaths = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

try {
  $st = db()->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM page_views
     WHERE created_at >= ? AND user_id = ?
     GROUP BY DATE(created_at) ORDER BY d ASC"
  );
  $st->execute([$since, $uid]);
  $daily = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

// Просмотры своих видео/каналов чужими (по path содержит slug)
$trafficDaily = [];
try {
  $likeParts = [];
  $params = [$since];
  foreach (array_slice($videos, 0, 15) as $v) {
    if (!empty($v['slug'])) {
      $likeParts[] = 'path LIKE ?';
      $params[] = '%/' . $v['slug'] . '%';
    }
  }
  foreach (array_slice($channels, 0, 10) as $c) {
    if (!empty($c['slug'])) {
      $likeParts[] = 'path LIKE ?';
      $params[] = '%/' . $c['slug'] . '%';
    }
  }
  $likeParts[] = 'path LIKE ?';
  $params[] = '%username=' . $user['username'] . '%';
  $likeParts[] = 'path LIKE ?';
  $params[] = '%/u/' . $user['username'] . '%';

  if ($likeParts) {
    $sql = 'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM page_views WHERE created_at >= ? AND (' . implode(' OR ', $likeParts) . ') GROUP BY DATE(created_at) ORDER BY d ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    $trafficDaily = $st->fetchAll() ?: [];
  }
} catch (Throwable $e) {}

$maxDay = 1;
foreach ($trafficDaily as $row) $maxDay = max($maxDay, (int)$row['c']);
foreach ($daily as $row) $maxDay = max($maxDay, (int)$row['c']);

$studio_title = 'Аналитика';
$studio_active = 'analytics';
require __DIR__ . '/_layout.php';

$uiMode = $_COOKIE['pl_ui_mode'] ?? 'streamlife';
$isTg = ($uiMode === 'telegram');
?>
<style>
.an-range{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.an-range a{padding:8px 14px;border-radius:18px;background:#212121;color:#aaa;font-size:13px;text-decoration:none}
.an-range a.on{background:#3ea6ff;color:#0f0f0f;font-weight:600}
body.pl-theme-telegram .an-range a.on, .tg-an .an-range a.on{background:#2AABEE;color:#fff}
.an-chart{display:flex;align-items:flex-end;gap:4px;height:140px;padding:12px 4px;background:#121212;border-radius:12px;overflow-x:auto}
.an-bar{flex:1;min-width:10px;background:linear-gradient(180deg,#3ea6ff,#1a5f99);border-radius:4px 4px 0 0;position:relative}
.tg-an .an-bar{background:linear-gradient(180deg,#2AABEE,#1a6fa0)}
.an-bar span{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);font-size:10px;color:#aaa;white-space:nowrap;display:none}
.an-bar:hover span{display:block}
.an-table td,.an-table th{font-size:13px}
.an-thumb{width:64px;height:36px;object-fit:cover;border-radius:4px;background:#222}
</style>
<div class="<?= $isTg ? 'tg-an' : '' ?>">
  <h1 class="st-h1">Аналитика <?= $isTg ? '<span style="font-size:12px;color:#2AABEE">Telegram UI</span>' : '<span style="font-size:12px;color:#aaa">Платформа</span>' ?></h1>
  <p class="st-sub">Период: последние <?= (int)$days ?> дн. · данные с ваших каналов, видео и просмотров страниц</p>

  <div class="an-range">
    <?php foreach ([7 => '7 дней', 28 => '28 дней', 90 => '90 дней', 365 => '1 год'] as $d => $lab): ?>
      <a href="?days=<?= $d ?>" class="<?= $days === $d ? 'on' : '' ?>"><?= $lab ?></a>
    <?php endforeach; ?>
  </div>

  <div class="st-cards">
    <div class="st-card"><div class="lbl">Просмотры видео</div><div class="val"><?= number_format($totalVideoViews) ?></div></div>
    <div class="st-card"><div class="lbl">Просмотры каналов</div><div class="val"><?= number_format($channelViews) ?></div></div>
    <div class="st-card"><div class="lbl">Лайки</div><div class="val"><?= number_format($totalLikes) ?></div></div>
    <div class="st-card"><div class="lbl">Комментарии</div><div class="val"><?= number_format($totalComments) ?></div></div>
    <div class="st-card"><div class="lbl">Каналов</div><div class="val"><?= count($channels) ?></div></div>
    <div class="st-card"><div class="lbl">Видео</div><div class="val"><?= count($videos) ?></div></div>
  </div>

  <div class="st-panel">
    <h2>Трафик по дням (ваши страницы)</h2>
    <?php if (!$trafficDaily): ?>
      <p class="muted">Пока мало данных за период — заходите сами и делитесь ссылками, график появится.</p>
    <?php else: ?>
      <div class="an-chart">
        <?php foreach ($trafficDaily as $row):
          $h = max(4, (int)round(((int)$row['c'] / $maxDay) * 120));
          ?>
          <div class="an-bar" style="height:<?= $h ?>px" title="<?= htmlspecialchars($row['d'] . ': ' . $row['c']) ?>">
            <span><?= (int)$row['c'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="muted" style="margin-top:8px">Столбцы = просмотры ваших видео/каналов/профиля по дням</p>
    <?php endif; ?>
  </div>

  <div class="st-panel">
    <h2>Ваша активность на сайте</h2>
    <?php if (!$daily): ?>
      <p class="muted">Нет записей page_views за период.</p>
    <?php else: ?>
      <div class="an-chart">
        <?php
        $maxA = 1;
        foreach ($daily as $row) $maxA = max($maxA, (int)$row['c']);
        foreach ($daily as $row):
          $h = max(4, (int)round(((int)$row['c'] / $maxA) * 120));
          ?>
          <div class="an-bar" style="height:<?= $h ?>px;opacity:.75" title="<?= htmlspecialchars($row['d'] . ': ' . $row['c']) ?>">
            <span><?= (int)$row['c'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="st-panel">
    <h2>Топ видео по просмотрам</h2>
    <?php if (!$videos): ?>
      <p class="muted">Видео пока нет. <a href="/platforma/studio/import.php">Импортировать</a></p>
    <?php else: ?>
      <table class="st-table an-table">
        <tr><th></th><th>Название</th><th>Просмотры</th><th>Лайки</th><th>Комменты</th><th>Статус</th></tr>
        <?php foreach (array_slice($videos, 0, 15) as $v): ?>
          <tr>
            <td><?php if (!empty($v['thumbnail_url'])): ?><img class="an-thumb" src="<?= htmlspecialchars($v['thumbnail_url']) ?>" alt=""><?php endif; ?></td>
            <td>
              <?php if (!empty($v['slug'])): ?>
                <a href="/video/<?= htmlspecialchars($v['slug']) ?>"><?= htmlspecialchars(mb_substr((string)$v['title'], 0, 60)) ?></a>
              <?php else: ?>
                <?= htmlspecialchars(mb_substr((string)$v['title'], 0, 60)) ?>
              <?php endif; ?>
            </td>
            <td><?= number_format((int)($v['views_count'] ?? 0)) ?></td>
            <td><?= number_format((int)($v['likes_count'] ?? 0)) ?></td>
            <td><?= number_format((int)($v['comments_count'] ?? 0)) ?></td>
            <td class="muted"><?= htmlspecialchars((string)($v['status'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="st-panel">
    <h2>Каналы</h2>
    <?php if (!$channels): ?>
      <p class="muted">Каналов нет. <a href="/new_channel.php">Создать</a></p>
    <?php else: ?>
      <table class="st-table an-table">
        <tr><th>Название</th><th>Тип</th><th>Статус</th><th>Просмотры</th></tr>
        <?php foreach ($channels as $c): ?>
          <tr>
            <td>
              <?php if (!empty($c['slug'])): ?>
                <a href="/channel/<?= htmlspecialchars($c['slug']) ?>"><?= htmlspecialchars($c['title']) ?></a>
              <?php else: ?>
                <?= htmlspecialchars($c['title']) ?>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)($c['type'] ?? 'tv')) ?></td>
            <td><?= htmlspecialchars((string)($c['status'] ?? '')) ?></td>
            <td><?= number_format((int)($c['views'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($topPaths): ?>
  <div class="st-panel">
    <h2>Топ путей (page_views)</h2>
    <table class="st-table an-table">
      <tr><th>Путь</th><th>Просмотры</th></tr>
      <?php foreach ($topPaths as $p): ?>
        <tr>
          <td class="muted" style="max-width:420px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($p['path']) ?></td>
          <td><?= number_format((int)$p['c']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <div class="st-panel">
    <h2>Что ещё как на YouTube</h2>
    <ul class="muted" style="line-height:1.7;padding-left:18px;font-size:13px">
      <li>Сводка за 7 / 28 / 90 / 365 дней</li>
      <li>Просмотры · лайки · комментарии · каналы</li>
      <li>График трафика по дням</li>
      <li>Топ контента с ссылками</li>
      <li>Студия: <a href="/platforma/studio/">dashboard</a> · <a href="/platforma/studio/content.php">контент</a> · <a href="/platforma/studio/channel.php">оформление</a></li>
    </ul>
  </div>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>
