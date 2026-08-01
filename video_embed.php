<?php
/**
 * Публичный embed-плеер видео для вставки на сторонние сайты.
 * /video_embed.php?slug=... или ?id=...
 * Минимальная страница: только плеер, без шапки/чата.
 */
require_once __DIR__ . '/includes/functions.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$id = (int)($_GET['id'] ?? 0);

try {
  if ($slug !== '') {
    $stmt = db()->prepare("SELECT * FROM videos WHERE slug = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$slug]);
  } elseif ($id > 0) {
    $stmt = db()->prepare("SELECT * FROM videos WHERE id = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$id]);
  } else {
    $stmt = null;
  }
  $video = $stmt ? $stmt->fetch() : null;
} catch (Throwable $e) {
  $video = null;
}

if (!$video) {
  http_response_code(404);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><body style="margin:0;background:#000;color:#888;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh">Видео недоступно</body></html>';
  exit;
}

$platform = (string)($video['platform'] ?? 'iframe');
$embedUrl = (string)($video['embed_url'] ?? '');
$title = (string)($video['title'] ?? 'Видео');
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: ALLOWALL'); // разрешаем iframe на чужих сайтах
// CSP frame-ancestors * — через meta не всегда; для shared host X-Frame-Options ALLOWALL не стандарт, но
// многие хостинги режут DENY. Уберём запрет:
header_remove('X-Frame-Options');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  html,body{margin:0;padding:0;background:#000;width:100%;height:100%;overflow:hidden}
  .wrap{position:absolute;inset:0}
  iframe,video{position:absolute;inset:0;width:100%;height:100%;border:0;background:#000}
</style>
</head>
<body>
<div class="wrap">
<?php if ($platform === 'mp4'): ?>
  <video src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>" controls playsinline autoplay></video>
<?php elseif ($platform === 'm3u8'): ?>
  <video id="v" controls playsinline></video>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
  <script>
    (function(){
      var src = <?= json_encode($embedUrl) ?>;
      var v = document.getElementById('v');
      if (window.Hls && Hls.isSupported()) { var h = new Hls(); h.loadSource(src); h.attachMedia(v); v.play().catch(function(){}); }
      else { v.src = src; }
    })();
  </script>
<?php else: ?>
  <iframe src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>"
    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
    allowfullscreen loading="lazy"></iframe>
<?php endif; ?>
</div>
</body>
</html>
