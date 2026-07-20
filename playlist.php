<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$stmt = db()->prepare('SELECT p.*, u.username AS owner_name FROM playlists p JOIN users u ON u.id = p.user_id WHERE p.slug = ?');
$stmt->execute([$slug]);
$playlist = $stmt->fetch();
if (!$playlist) { http_response_code(404); require_once __DIR__ . '/includes/header.php'; echo '<div class="container"><p>Плейлист не найден</p></div>'; require_once __DIR__ . '/includes/footer.php'; exit; }

$user = current_user();
$isOwner = $user && (int)$user['id'] === (int)$playlist['user_id'];
if (!$playlist['is_public'] && !$isOwner) { http_response_code(403); require_once __DIR__ . '/includes/header.php'; echo '<div class="container"><p>Плейлист приватный</p></div>'; require_once __DIR__ . '/includes/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
  csrf_verify();
  if (($_POST['action'] ?? '') === 'remove') {
    db()->prepare('DELETE FROM playlist_items WHERE playlist_id = ? AND video_id = ?')->execute([$playlist['id'], (int)$_POST['video_id']]);
  } elseif (($_POST['action'] ?? '') === 'delete_playlist') {
    db()->prepare('DELETE FROM playlists WHERE id = ?')->execute([$playlist['id']]);
    redirect('/playlists.php');
  }
  redirect('/playlist.php?slug=' . $slug);
}

$pageTitle = $playlist['title'];
require_once __DIR__ . '/includes/header.php';

$stmt = db()->prepare(
  "SELECT v.*, pi.position FROM playlist_items pi JOIN videos v ON v.id = pi.video_id
   WHERE pi.playlist_id = ? AND v.status = 'published' ORDER BY pi.position, pi.added_at"
);
$stmt->execute([$playlist['id']]);
$videos = $stmt->fetchAll();
?>
<div class="container" style="max-width:800px">
  <h1>🎞️ <?= e($playlist['title']) ?></h1>
  <p style="color:var(--text-dim);font-size:13px">
    от <a href="/profile.php?username=<?= urlencode($playlist['owner_name']) ?>" style="color:var(--accent-2)"><?= e($playlist['owner_name']) ?></a>
    · <?= count($videos) ?> видео <?= $playlist['is_public'] ? '· 🌐 публичный' : '· 🔒 приватный' ?>
  </p>
  <?php if ($isOwner): ?>
    <form method="POST" onsubmit="return confirm('Удалить плейлист целиком?')" style="margin-bottom:16px">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_playlist">
      <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger)">Удалить плейлист</button>
    </form>
  <?php endif; ?>

  <?php if (!$videos): ?><div class="empty-state"><p>Видео пока нет — добавляйте их со страницы просмотра кнопкой «В плейлист»</p></div><?php endif; ?>

  <?php foreach ($videos as $i => $v): ?>
    <div class="card" style="display:flex;gap:12px;align-items:center;padding:10px;margin-bottom:8px">
      <span style="color:var(--text-dim);width:24px;text-align:center"><?= $i + 1 ?></span>
      <a href="/video.php?slug=<?= e($v['slug']) ?>&playlist=<?= e($slug) ?>" style="display:flex;gap:12px;flex:1;text-decoration:none;color:inherit;align-items:center">
        <div style="width:100px;aspect-ratio:16/9;background:#111 url('<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>') center/cover;border-radius:6px;flex-shrink:0"></div>
        <b style="font-size:13px"><?= e(mb_substr($v['title'], 0, 60)) ?></b>
      </a>
      <?php if ($isOwner): ?>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="video_id" value="<?= (int)$v['id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm">Убрать</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
