<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT v.*, c.title AS channel_title FROM videos v JOIN channels c ON c.id = v.channel_id WHERE v.id = ?');
$stmt->execute([$id]);
$video = $stmt->fetch();
if (!$video) { http_response_code(404); die('Видео не найдено'); }

$isModerator = in_array($__user['role'], ['moderator', 'admin'], true);
if (!$isModerator && (int)$video['user_id'] !== (int)$__user['id']) {
  http_response_code(403); die('Доступ только для модераторов или автора видео');
}

$pageTitle = 'Превью: ' . $video['title'];
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:900px">
  <div class="alert alert-warning">Это предпросмотр — видео ещё не опубликовано (статус: <?= e($video['status']) ?>)<?= $video['reject_reason'] ? ', причина отклонения: ' . e($video['reject_reason']) : '' ?></div>
  <div class="player-wrap" style="position:relative;padding-top:56.25%;background:#000;border-radius:10px;overflow:hidden">
    <?php if ($video['platform'] === 'mp4'): ?>
      <video src="<?= e($video['embed_url']) ?>" controls style="position:absolute;top:0;left:0;width:100%;height:100%"></video>
    <?php else: ?>
      <iframe src="<?= e($video['embed_url']) ?>" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;border:0"></iframe>
    <?php endif; ?>
  </div>
  <h1 style="margin:16px 0 6px;font-size:20px"><?= e($video['title']) ?></h1>
  <p style="color:var(--text-dim)">Канал: <?= e($video['channel_title']) ?></p>
  <?php if ($video['description']): ?><p><?= e($video['description']) ?></p><?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
