<?php
declare(strict_types=1);
$studio_title = 'Контент';
$studio_active = 'content';
$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
$user = current_user();
if (!$user) { header('Location: /login.php'); exit; }
$uid = (int)$user['id'];
$videos = [];
try {
  $st = db()->prepare(
    "SELECT v.id, v.title, v.slug, v.thumbnail_url, v.views_count, v.status, v.created_at, c.title AS channel_title
     FROM videos v LEFT JOIN channels c ON c.id = v.channel_id
     WHERE v.user_id = ? OR c.owner_id = ?
     ORDER BY v.id DESC LIMIT 100"
  );
  $st->execute([$uid, $uid]);
  $videos = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $st = db()->prepare('SELECT * FROM videos WHERE user_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$uid]);
    $videos = $st->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}
require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Контент</h1>
<p class="st-sub">Видео ваших каналов · <a href="/platforma/studio/import.php" style="color:var(--st-accent2)">Импорт</a></p>
<?php if (!empty($_GET['ok'])): ?>
  <div class="st-panel">Видео добавлено.</div>
<?php endif; ?>
<div class="st-panel">
  <?php if (!$videos): ?>
    <p class="muted">Пока нет видео. <a href="/platforma/studio/import.php">Импортировать</a></p>
  <?php else: ?>
    <table class="st-table">
      <thead><tr><th></th><th>Название</th><th>Канал</th><th>Просм.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($videos as $v): ?>
        <tr>
          <td>
            <?php if (!empty($v['thumbnail_url'])): ?>
              <img class="st-thumb" src="<?= htmlspecialchars($v['thumbnail_url'], ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
              <div class="st-thumb"></div>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($v['title'] ?? '', ENT_QUOTES, 'UTF-8') ?><br><span class="muted"><?= htmlspecialchars($v['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="muted"><?= htmlspecialchars($v['channel_title'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int)($v['views_count'] ?? 0) ?></td>
          <td><a class="btn btn-outline" href="/video.php?slug=<?= htmlspecialchars(urlencode($v['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">Открыть</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>
