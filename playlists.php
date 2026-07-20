<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $title = trim(mb_substr($_POST['title'] ?? '', 0, 150));
  if ($title !== '') {
    $slug = slugify($title);
    $stmt = db()->prepare('INSERT INTO playlists (user_id, title, slug, is_public) VALUES (?, ?, ?, ?)');
    $stmt->execute([$__user['id'], $title, $slug, isset($_POST['is_public']) ? 1 : 0]);
    redirect('/playlist.php?slug=' . $slug);
  }
}

$pageTitle = 'Мои плейлисты';
require_once __DIR__ . '/includes/header.php';

$stmt = db()->prepare(
  "SELECT p.*, COUNT(pi.id) AS video_count FROM playlists p
   LEFT JOIN playlist_items pi ON pi.playlist_id = p.id
   WHERE p.user_id = ? GROUP BY p.id ORDER BY p.created_at DESC"
);
$stmt->execute([$__user['id']]);
$playlists = $stmt->fetchAll();
?>
<div class="container" style="max-width:700px">
  <h1>🎞️ Мои плейлисты</h1>

  <form method="POST" style="display:flex;gap:8px;margin:16px 0;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="text" name="title" placeholder="Название нового плейлиста" required style="flex:1;padding:10px;min-width:200px">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" name="is_public" checked> Публичный</label>
    <button type="submit" class="btn btn-primary">Создать</button>
  </form>

  <?php if (!$playlists): ?><div class="empty-state"><p>Плейлистов пока нет</p></div><?php endif; ?>
  <?php foreach ($playlists as $p): ?>
    <a href="/playlist.php?slug=<?= e($p['slug']) ?>" class="card" style="display:block;padding:14px;margin-bottom:10px;text-decoration:none;color:inherit">
      <b><?= e($p['title']) ?></b> <span style="color:var(--text-dim);font-size:13px">· <?= (int)$p['video_count'] ?> видео <?= $p['is_public'] ? '· 🌐 публичный' : '· 🔒 приватный' ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
