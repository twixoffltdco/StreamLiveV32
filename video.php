<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/recommendations.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$stmt = db()->prepare("SELECT v.*, c.title AS channel_title, c.slug AS channel_slug
                        FROM videos v JOIN channels c ON c.id = v.channel_id
                        WHERE v.slug = ? AND v.status = 'published'");
$stmt->execute([$slug]);
$video = $stmt->fetch();
if (!$video) { http_response_code(404); require_once __DIR__ . '/includes/header.php'; echo '<div class="container"><p>Видео не найдено</p></div>'; require_once __DIR__ . '/includes/footer.php'; exit; }

$user = current_user();
db()->prepare('UPDATE videos SET views_count = views_count + 1 WHERE id = ?')->execute([$video['id']]);
record_video_view((int)$video['id'], $user['id'] ?? null);

$liked = $fav = false;
if ($user) {
  $s = db()->prepare('SELECT id FROM video_likes WHERE video_id = ? AND user_id = ?');
  $s->execute([$video['id'], $user['id']]);
  $liked = (bool)$s->fetch();
  $s = db()->prepare('SELECT id FROM video_favorites WHERE video_id = ? AND user_id = ?');
  $s->execute([$video['id'], $user['id']]);
  $fav = (bool)$s->fetch();
}

$comments = db()->prepare('SELECT vc.*, u.username FROM video_comments vc JOIN users u ON u.id = vc.user_id WHERE vc.video_id = ? ORDER BY vc.created_at DESC LIMIT 100');
$comments->execute([$video['id']]);
$comments = $comments->fetchAll();

$pageTitle = $video['title'];
$seoDescription = mb_substr($video['description'] ?: $video['title'], 0, 200);
$seoImage = $video['thumbnail_url'];
require_once __DIR__ . '/includes/header.php'; // теперь через общий шаблон — та же шапка/меню/мобильная адаптация, что и везде на сайте
?>
<div class="container" style="max-width:900px">

  <div class="player-wrap" style="position:relative;padding-top:56.25%;background:#000;border-radius:10px;overflow:hidden">
    <?php if ($video['platform'] === 'mp4'): ?>
      <video src="<?= e($video['embed_url']) ?>" controls style="position:absolute;top:0;left:0;width:100%;height:100%"></video>
    <?php elseif ($video['platform'] === 'm3u8'): ?>
      <video id="hlsPlayer" controls style="position:absolute;top:0;left:0;width:100%;height:100%"></video>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
      <script>
        var src = <?= json_encode($video['embed_url']) ?>;
        var v = document.getElementById('hlsPlayer');
        if (window.Hls && Hls.isSupported()) { var hls = new Hls(); hls.loadSource(src); hls.attachMedia(v); }
        else { v.src = src; }
      </script>
    <?php elseif ($video['platform'] === 'tiktok'): ?>
      <blockquote class="tiktok-embed" cite="<?= e($video['source_url']) ?>" style="max-width:100%">
        <a href="<?= e($video['source_url']) ?>">TikTok video</a>
      </blockquote>
      <script async src="https://www.tiktok.com/embed.js"></script>
    <?php elseif ($video['platform'] === 'twitter'): ?>
      <blockquote class="twitter-tweet"><a href="<?= e($video['source_url']) ?>"></a></blockquote>
      <script async src="https://platform.twitter.com/widgets.js"></script>
    <?php elseif ($video['platform'] === 'reddit'): ?>
      <blockquote class="reddit-embed-bq" style="height:100%">
        <a href="<?= e($video['source_url']) ?>">Reddit post</a>
      </blockquote>
      <script async src="https://embed.reddit.com/widgets.js"></script>
    <?php else: ?>
      <iframe src="<?= e($video['embed_url']) ?>" allowfullscreen loading="lazy"
        style="position:absolute;top:0;left:0;width:100%;height:100%;border:0"></iframe>
    <?php endif; ?>
  </div>

  <h1 style="margin:16px 0 6px;font-size:20px"><?= e($video['title']) ?></h1>
  <p style="color:var(--text-dim);font-size:13px">
    <a href="/channel.php?slug=<?= e($video['channel_slug']) ?>" style="color:var(--accent-2)"><?= e($video['channel_title']) ?></a>
    · <?= (int)$video['views_count'] ?> просмотров
  </p>

  <div class="video-actions" style="display:flex;gap:10px;margin:12px 0;flex-wrap:wrap">
    <button id="playlistBtn" class="btn btn-outline btn-sm">➕ В плейлист</button>
    <?php if (in_array($video['platform'], ['mp4', 'm3u8'], true) && $user): ?>
    <form method="POST" action="/watch_room?action=create" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="video_id" value="<?= (int)$video['id'] ?>">
      <button type="submit" class="btn btn-outline btn-sm">👥 Смотреть вместе</button>
    </form>
    <?php endif; ?>
    <button id="likeBtn" data-id="<?= (int)$video['id'] ?>" class="btn btn-outline btn-sm <?= $liked ? 'active' : '' ?>">👍 <span id="likeCount"><?= (int)$video['likes_count'] ?></span></button>
    <button id="favBtn" data-id="<?= (int)$video['id'] ?>" class="btn btn-outline btn-sm <?= $fav ? 'active' : '' ?>">⭐ В избранное</button>
  </div>

  <?php if ($video['description']): ?><p style="font-size:14px;white-space:pre-line"><?= e($video['description']) ?></p><?php endif; ?>
  <?php if ($video['tags']): ?><p class="tags">
    <?php foreach (explode(',', $video['tags']) as $t): ?><span class="tag" style="display:inline-block;background:var(--card);padding:3px 8px;border-radius:20px;font-size:12px;margin:2px">#<?= e(trim($t)) ?></span> <?php endforeach; ?>
  </p><?php endif; ?>

  <?php $recs = get_recommended_videos((int)$video['id'], 8); if ($recs): ?>
  <h2 style="font-size:16px;margin-top:24px">Рекомендуем</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px">
    <?php foreach ($recs as $r): ?>
      <a href="/video.php?slug=<?= e($r['slug']) ?>" style="text-decoration:none;color:inherit">
        <div style="aspect-ratio:16/9;background:#111 url('<?= e($r['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>') center/cover;border-radius:8px"></div>
        <div style="padding:6px 2px;font-size:12px"><?= e(mb_substr($r['title'], 0, 50)) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <h2 id="comments" style="font-size:16px;margin-top:24px">Комментарии (<?= (int)$video['comments_count'] ?>)</h2>
  <?php if ($user): ?>
    <form method="post" action="/video_comment_add" style="display:flex;gap:8px;margin:10px 0">
      <?= csrf_field() ?>
      <input type="hidden" name="video_id" value="<?= (int)$video['id'] ?>">
      <textarea name="message" rows="2" maxlength="1000" placeholder="Комментарий..." style="flex:1;padding:8px;border-radius:8px"></textarea>
      <button type="submit" class="btn btn-primary btn-sm">Отправить</button>
    </form>
  <?php else: ?>
    <p><a href="/auth/login.php" style="color:var(--accent-2)">Войдите</a>, чтобы оставить комментарий</p>
  <?php endif; ?>

  <div class="comments-list">
    <?php foreach ($comments as $c): ?>
      <div class="comment" style="padding:10px 0;border-top:1px solid var(--border,rgba(255,255,255,.08))">
        <b><?= e($c['username']) ?></b>
        <span class="date" style="color:var(--text-dim);font-size:12px"> · <?= e($c['created_at']) ?></span>
        <p style="margin:4px 0 0;white-space:pre-line"><?= e($c['message']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
function toggleAction(btnId, url, videoId, countId) {
  document.getElementById(btnId).addEventListener('click', function () {
    var self = this;
    fetch(url, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({video_id: videoId}) })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) { alert(d.error || 'Ошибка'); return; }
        self.classList.toggle('active', d.liked ?? d.favorited);
        if (countId && 'count' in d) document.getElementById(countId).textContent = d.count;
      });
  });
}
toggleAction('likeBtn', '/video_like.php', <?= (int)$video['id'] ?>, 'likeCount');
toggleAction('favBtn', '/video_favorite.php', <?= (int)$video['id'] ?>, null);

document.getElementById('playlistBtn').addEventListener('click', function () {
  <?php if (!$user): ?>
  location.href = '/auth/login.php';
  <?php else: ?>
  var title = prompt('Название нового плейлиста (или отмена, если хотите добавить в существующий через страницу /playlists.php)');
  if (!title) return;
  fetch('/playlist_add', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({video_id: <?= (int)$video['id'] ?>, new_title: title})
  }).then(function (r) { return r.json(); }).then(function (d) {
    alert(d.ok ? 'Добавлено в плейлист «' + title + '»' : (d.error || 'Ошибка'));
  });
  <?php endif; ?>
});

<?php if (in_array($video['platform'], ['mp4', 'm3u8'], true) && $user): ?>
// Продолжить просмотр: сохраняем позицию раз в 5 сек, при заходе с ?resume=1 перематываем на сохранённое место
(function () {
  var v = document.querySelector('#hlsPlayer, .player-wrap video');
  if (!v) return;
  var videoId = <?= (int)$video['id'] ?>;
  var resumeRequested = <?= isset($_GET['resume']) ? 'true' : 'false' ?>;

  <?php if (isset($_GET['resume'])): ?>
  fetch('/video_progress_get?video_id=' + videoId).then(function (r) { return r.json(); }).then(function (d) {
    if (d.ok && d.position > 5) v.currentTime = d.position;
  });
  <?php endif; ?>

  var lastSaved = 0;
  v.addEventListener('timeupdate', function () {
    if (Math.abs(v.currentTime - lastSaved) < 5) return; // не чаще раза в 5 сек реального времени видео
    lastSaved = v.currentTime;
    fetch('/video_progress_save', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({video_id: videoId, position: Math.floor(v.currentTime), duration: Math.floor(v.duration || 0)})
    });
  });
})();
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
