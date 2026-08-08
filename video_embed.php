<?php
/**
 * Публичный embed-плеер видео для вставки на сторонние сайты.
 * /video_embed.php?slug=... или ?id=...
 * Минимальная страница: только плеер, без шапки/чата.
 * Поддерживает премьеры (как в video.php).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__ . '/includes/paid_access.php')) require_once __DIR__ . '/includes/paid_access.php';

// Подключаем помощники премьер, если есть
if (is_file(__DIR__ . '/includes/premiere_helpers.php')) {
    require_once __DIR__ . '/includes/premiere_helpers.php';
    if (function_exists('premiere_ensure_columns')) premiere_ensure_columns();
    if (function_exists('premiere_mark_used_if_ended')) premiere_mark_used_if_ended();
}

$slug = trim((string)($_GET['slug'] ?? ''));
$id = (int)($_GET['id'] ?? 0);

// Получаем видео (как в video.php, с джойном каналов, но для embed достаточно только видео)
try {
    if ($slug !== '') {
        $stmt = db()->prepare("SELECT v.*, c.title AS channel_title, c.slug AS channel_slug, c.type AS channel_type, c.owner_id AS channel_owner_id
                                FROM videos v JOIN channels c ON c.id = v.channel_id
                                WHERE v.slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $video = $stmt->fetch() ?: null;
    } elseif ($id > 0) {
        $stmt = db()->prepare("SELECT v.*, c.title AS channel_title, c.slug AS channel_slug, c.type AS channel_type, c.owner_id AS channel_owner_id
                                FROM videos v JOIN channels c ON c.id = v.channel_id
                                WHERE v.id = ? LIMIT 1");
        $stmt->execute([$id]);
        $video = $stmt->fetch() ?: null;
    } else {
        $video = null;
    }
} catch (Throwable $e) {
    // fallback без JOIN
    try {
        if ($slug !== '') {
            $stmt = db()->prepare("SELECT * FROM videos WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
            $video = $stmt->fetch() ?: null;
        } elseif ($id > 0) {
            $stmt = db()->prepare("SELECT * FROM videos WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $video = $stmt->fetch() ?: null;
        } else {
            $video = null;
        }
    } catch (Throwable $e2) {
        $video = null;
    }
}

// Проверка статуса и прав (как в video.php)
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

if ($video && function_exists('paid_embed_is_forbidden_for_video') && paid_embed_is_forbidden_for_video($video)) {
    paid_embed_blocked_page('video');
}
if (!$video) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="margin:0;background:#000;color:#888;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh">Видео недоступно</body></html>';
    exit;
}

// Определяем премьеру (как в video.php)
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

$platform = (string)($video['platform'] ?? 'iframe');
$embedUrl = (string)($video['embed_url'] ?? '');
$sourceUrl = (string)($video['source_url'] ?? '');
$title = (string)($video['title'] ?? 'Видео');
$thumbnail = (string)($video['thumbnail_url'] ?? '');

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: ALLOWALL');
header_remove('X-Frame-Options');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  html,body{margin:0;padding:0;background:#000;width:100%;height:100%;overflow:hidden;font-family:sans-serif}
  .wrap{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
  .wrap > *{max-width:100%;max-height:100%;margin:auto}
  iframe,video{position:absolute;inset:0;width:100%;height:100%;border:0;background:#000}
  .wrap .tiktok-embed,
  .wrap .twitter-tweet,
  .wrap .reddit-embed-bq{width:100%;height:100%;display:flex;align-items:center;justify-content:center}
  .wrap .tiktok-embed iframe,
  .wrap .twitter-tweet iframe,
  .wrap .reddit-embed-bq iframe{max-width:100%;max-height:100%}

  /* Блок премьеры (точно как в video.php) */
  .yt-premiere-box{position:relative;width:100%;aspect-ratio:16/9;border-radius:0;overflow:hidden;background:#0a0a0a}
  .yt-premiere-box img{width:100%;height:100%;object-fit:cover;opacity:.45;filter:blur(2px)}
  .yt-premiere-box .premiere-overlay{position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.35),rgba(0,0,0,.75));display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:20px;color:#fff}
  .yt-premiere-box .premiere-label{font-size:13px;letter-spacing:.06em;text-transform:uppercase;opacity:.85;margin-bottom:8px}
  .yt-premiere-box .premiere-title{font-size:clamp(18px,3vw,26px);font-weight:700;max-width:90%;margin-bottom:16px;line-height:1.25}
  .yt-premiere-box .premiere-time{font-size:14px;opacity:.8;margin-bottom:10px}
  .yt-premiere-box .premiere-countdown{font-variant-numeric:tabular-nums;font-size:clamp(28px,5vw,48px);font-weight:800;letter-spacing:.04em}
  .yt-premiere-box .premiere-note{margin-top:14px;font-size:13px;opacity:.7}
</style>
</head>
<body>
<div class="wrap">

<?php if (!empty($__premiereWait)): ?>
  <?php
    // Получаем обложку (как в video.php)
    $__premThumb = trim((string)($video['thumbnail_url'] ?? ''));
    if ($__premThumb === '' && is_file(__DIR__ . '/includes/video_thumb.php')) {
        require_once __DIR__ . '/includes/video_thumb.php';
        if (function_exists('video_thumb_url')) $__premThumb = video_thumb_url($video);
    }
    // canvas-источник для превью (опционально)
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
  <div class="yt-premiere-box">
    <div data-thumb-canvas data-thumb-src="<?= htmlspecialchars($__premCanvas, ENT_QUOTES, 'UTF-8') ?>" style="position:absolute;inset:0">
      <img src="<?= htmlspecialchars($__premImg, ENT_QUOTES, 'UTF-8') ?>" alt="">
    </div>
    <div class="premiere-overlay">
      <div class="premiere-label">Премьера</div>
      <div class="premiere-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="premiere-time">Начало <b><?= date('d.m.Y H:i', (int)$__premiereAt) ?></b> (МСК ≈ локальное)</div>
      <div id="premiereCountdown" class="premiere-countdown">—</div>
      <div class="premiere-note">Ожидание премьеры · видео начнётся автоматически</div>
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
  <!-- Плеер (точно как в video.php) -->
  <?php if ($platform === 'mp4'): ?>
    <video src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>" controls playsinline autoplay></video>

  <?php elseif ($platform === 'm3u8'): ?>
    <video id="hlsPlayer" controls playsinline></video>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
    <script>
      var src = <?= json_encode($embedUrl) ?>;
      var v = document.getElementById('hlsPlayer');
      if (window.Hls && Hls.isSupported()) { var hls = new Hls(); hls.loadSource(src); hls.attachMedia(v); }
      else { v.src = src; }
    </script>

  <?php elseif ($platform === 'tiktok' && $sourceUrl !== ''): ?>
    <blockquote class="tiktok-embed" cite="<?= htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') ?>" style="max-width:100%">
      <a href="<?= htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') ?>">TikTok video</a>
    </blockquote>
    <script async src="https://www.tiktok.com/embed.js"></script>

  <?php elseif ($platform === 'twitter' && $sourceUrl !== ''): ?>
    <blockquote class="twitter-tweet"><a href="<?= htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') ?>"></a></blockquote>
    <script async src="https://platform.twitter.com/widgets.js"></script>

  <?php elseif ($platform === 'reddit' && $sourceUrl !== ''): ?>
    <blockquote class="reddit-embed-bq" style="height:100%">
      <a href="<?= htmlspecialchars($sourceUrl, ENT_QUOTES, 'UTF-8') ?>">Reddit post</a>
    </blockquote>
    <script async src="https://embed.reddit.com/widgets.js"></script>

  <?php else: ?>
    <iframe src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>"
      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
      allowfullscreen loading="lazy"></iframe>
  <?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>