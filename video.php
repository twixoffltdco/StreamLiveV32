<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/recommendations.php';
require_once __DIR__ . '/includes/player_ads.php';
if (is_file(__DIR__ . '/includes/paid_access.php')) require_once __DIR__ . '/includes/paid_access.php';
if (is_file(__DIR__ . '/includes/video_url_fix.php')) require_once __DIR__ . '/includes/video_url_fix.php';


$slug = trim((string)($_GET['slug'] ?? ''));
if (is_file(__DIR__ . '/includes/premiere_helpers.php')) {
  require_once __DIR__ . '/includes/premiere_helpers.php';
  premiere_ensure_columns();
  if (function_exists('premiere_mark_used_if_ended')) premiere_mark_used_if_ended();
}
$video = null;
try {
  $stmt = db()->prepare("SELECT v.*, c.title AS channel_title, c.slug AS channel_slug, c.type AS channel_type, c.owner_id AS channel_owner_id
                          FROM videos v JOIN channels c ON c.id = v.channel_id
                          WHERE v.slug = ? LIMIT 1");
  $stmt->execute([$slug]);
  $video = $stmt->fetch() ?: null;
} catch (Throwable $e) {
  try {
    $stmt = db()->prepare("SELECT v.*, c.title AS channel_title, c.slug AS channel_slug
                            FROM videos v JOIN channels c ON c.id = v.channel_id WHERE v.slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $video = $stmt->fetch() ?: null;
  } catch (Throwable $e2) { $video = null; }
}


if ($video) {
  $__st = strtolower((string)($video['status'] ?? 'published'));
  $__userGate = function_exists('current_user') ? current_user() : null;
  $__own = $__userGate && (
    (int)($video['user_id'] ?? 0) === (int)$__userGate['id']
    || (int)($video['channel_owner_id'] ?? 0) === (int)$__userGate['id']
  );
  $__staff = $__userGate && in_array((string)($__userGate['role'] ?? ''), ['admin','moderator'], true);
  if (in_array($__st, ['draft','failed','deleted'], true) && !$__own && !$__staff) {
    $video = null;
  }
}


// Платный канал → без доступа нет просмотра (как канал ТВ/радио)
if ($video && function_exists('paid_require_video_access')) {
  $__userPaid = function_exists('current_user') ? current_user() : null;
  paid_require_video_access($video, $__userPaid);
}

if ($video && function_exists('video_row_fix_urls')) {
  $video = video_row_fix_urls($video);
}

if (!$video) { http_response_code(404); require_once __DIR__ . '/includes/header.php'; echo '<div class="container"><p>Видео не найдено</p></div>'; echo '<script src="/assets/js/video-thumb-canvas.js?v=3" defer></script>';
require_once __DIR__ . '/includes/footer.php'; exit; }

// Премьера как на YouTube: до старта — обложка + отсчёт, потом плеер
$__premiereWait = false;
$__premiereAt = 0;
try {
  if (function_exists('premiere_state') && $video) {
    $__ps = premiere_state($video);
    $__premiereAt = (int)($__ps['at'] ?? 0);
    $__premiereWait = !empty($__ps['wait']);
  } elseif (!empty($video['premiere_at'])) {
    $__premiereAt = (int)strtotime((string)$video['premiere_at']);
    if ($__premiereAt > time()) $__premiereWait = true;
  }
} catch (Throwable $e) {}

$pageTitle = $video['title'];
$seoDescription = mb_substr($video['description'] ?: $video['title'], 0, 200);
$seoImage = $video['thumbnail_url'];
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/youtube-watch.css?v=3">
<div class="container yt-watch" style="max-width:1100px">

  <?php if (!empty($__premiereWait)): ?>
  <?php
    $__premThumb = trim((string)($video['thumbnail_url'] ?? ''));
    if ($__premThumb === '' && is_file(__DIR__ . '/includes/video_thumb.php')) {
      require_once __DIR__ . '/includes/video_thumb.php';
      if (function_exists('video_thumb_url')) $__premThumb = video_thumb_url($video);
    }
  ?>
  <div class="yt-premiere-box" style="position:relative;width:100%;aspect-ratio:16/9;border-radius:12px;overflow:hidden;background:#0a0a0a;margin-bottom:12px">
    <?php
      $__premCanvas = '';
      if (function_exists('video_canvas_src')) $__premCanvas = video_canvas_src($video);
      if ($__premCanvas === '') {
        $__eu = (string)($video['embed_url'] ?? '');
        $__su = (string)($video['source_url'] ?? '');
        if (preg_match('/\.(mp4|webm|m3u8)($|\?)/i', $__eu)) $__premCanvas = $__eu;
        elseif (preg_match('/\.(mp4|webm|m3u8)($|\?)/i', $__su)) $__premCanvas = $__su;
      }
      $__premImg = $__premThumb !== '' ? $__premThumb : '/assets/img/video-placeholder.png';
    ?>
    <div data-thumb-canvas data-thumb-src="<?= e($__premCanvas) ?>" style="position:absolute;inset:0">
      <img src="<?= e($__premImg) ?>" alt="" style="width:100%;height:100%;object-fit:cover;opacity:.45;filter:blur(2px)">
    </div>
    <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.35),rgba(0,0,0,.75))"></div>
    <div style="position:relative;z-index:2;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:20px;color:#fff">
      <div style="font-size:13px;letter-spacing:.06em;text-transform:uppercase;opacity:.85;margin-bottom:8px">Премьера</div>
      <div style="font-size:clamp(18px,3vw,26px);font-weight:700;max-width:90%;margin-bottom:16px;line-height:1.25"><?= e($video['title'] ?? '') ?></div>
      <div style="font-size:14px;opacity:.8;margin-bottom:10px">Начало <b><?= e(date('d.m.Y H:i', (int)$__premiereAt)) ?></b> (МСК ≈ локальное)</div>
      <div id="premiereCountdown" style="font-variant-numeric:tabular-nums;font-size:clamp(28px,5vw,48px);font-weight:800;letter-spacing:.04em">—</div>
      <div style="margin-top:14px;font-size:13px;opacity:.7">Ожидание премьеры · видео начнётся автоматически</div>
    </div>
  </div>
  <script>
  (function(){
    var ts = <?= (int)$__premiereAt ?> * 1000;
    function pad(n){ return n < 10 ? '0'+n : ''+n; }
    function tick(){
      var d = ts - Date.now();
      var el = document.getElementById('premiereCountdown');
      if (!el) return;
      if (d <= 0) {
        el.textContent = '00:00:00';
        location.reload();
        return;
      }
      var s = Math.floor(d/1000);
      var days = Math.floor(s/86400); s %= 86400;
      var h = Math.floor(s/3600); s %= 3600;
      var m = Math.floor(s/60); s %= 60;
      el.textContent = (days > 0 ? days + 'д ' : '') + pad(h) + ':' + pad(m) + ':' + pad(s);
    }
    tick();
    setInterval(tick, 1000);
  })();
  </script>
  <?php else: ?>
  <div class="yt-player-box player-wrap">

    <?php if ($video['platform'] === 'mp4'): ?>
      <video src="<?= e($video['embed_url']) ?>" controls playsinline webkit-playsinline></video>
    <?php elseif ($video['platform'] === 'm3u8'): ?>
      <video id="hlsPlayer" controls playsinline webkit-playsinline></video>
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
      <iframe src="<?= e($video['embed_url']) ?>" allowfullscreen loading="lazy"></iframe>
    <?php endif; ?>
  
  <?php if (function_exists('player_ads_render')) player_ads_render('video'); ?>
</div>
  <?php endif; /* premiere countdown end */ ?>

  <h1 style="margin:16px 0 6px;font-size:20px"><?= e($video['title']) ?></h1>
  <p style="color:var(--text-dim);font-size:13px">
    <a href="/channel.php?slug=<?= e($video['channel_slug']) ?>" style="color:var(--accent-2)"><?= e($video['channel_title']) ?></a>
    · <?= (int)$video['views_count'] ?> просмотров
  </p>

  <div class="video-actions" style="display:flex;gap:10px;margin:12px 0;flex-wrap:wrap">
    <button id="playlistBtn" class="btn btn-outline btn-sm">➕ В плейлист</button>
    <button type="button" id="embedBtn" class="btn btn-outline btn-sm">⛶ Встроить</button>
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
        <?php
          $__rt = trim((string)($r['thumbnail_url'] ?? ''));
          if ($__rt === '' && function_exists('video_thumb_url')) $__rt = video_thumb_url($r);
          $__rcs = '';
          if (function_exists('video_canvas_src')) $__rcs = video_canvas_src($r);
          if ($__rcs === '') {
            $__eu = (string)($r['embed_url'] ?? '');
            $__su = (string)($r['source_url'] ?? '');
            if (preg_match('/\.(mp4|webm|m3u8)($|\?)/i', $__eu)) $__rcs = $__eu;
            elseif (preg_match('/\.(mp4|webm|m3u8)($|\?)/i', $__su)) $__rcs = $__su;
          }
          $__ri = $__rt !== '' ? $__rt : '/assets/img/video-placeholder.png';
        ?>
        <div data-thumb-canvas data-thumb-src="<?= e($__rcs) ?>" style="aspect-ratio:16/9;border-radius:8px;overflow:hidden;background:#111">
          <img src="<?= e($__ri) ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:block" loading="lazy">
        </div>
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

<?php
$__site = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$__embedUrl = $__site . '/embed_video.php?slug=' . rawurlencode($video['slug']);
$__embedCode = '<iframe width="560" height="315" src="' . htmlspecialchars($__embedUrl, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($video['title'], ENT_QUOTES, 'UTF-8') . '" frameborder="0" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="width:100%;aspect-ratio:16/9;border:0"></iframe>';
?>
<div class="yt-embed-modal" id="embedModal">
  <div class="box">
    <h3 style="margin:0 0 10px">Встроить видео</h3>
    <textarea id="embedCode" readonly><?= htmlspecialchars($__embedCode, ENT_QUOTES, 'UTF-8') ?></textarea>
    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
      <button type="button" class="btn btn-primary btn-sm" id="embedCopy">Копировать</button>
      <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($__embedUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Открыть</a>
      <button type="button" class="btn btn-outline btn-sm" id="embedClose">Закрыть</button>
    </div>
  </div>
</div>
<script>
(function(){
  var m = document.getElementById('embedModal');
  var b = document.getElementById('embedBtn');
  if (!b || !m) return;
  b.addEventListener('click', function(){ m.classList.add('open'); });
  document.getElementById('embedClose').onclick = function(){ m.classList.remove('open'); };
  m.addEventListener('click', function(e){ if (e.target === m) m.classList.remove('open'); });
  document.getElementById('embedCopy').onclick = function(){
    var t = document.getElementById('embedCode'); t.select();
    try { navigator.clipboard.writeText(t.value); this.textContent = 'Скопировано'; }
    catch (e) { document.execCommand('copy'); }
  };
})();
</script>

<?php echo '<script src="/assets/js/video-thumb-canvas.js?v=3" defer></script>';
require_once __DIR__ . '/includes/footer.php'; ?>
