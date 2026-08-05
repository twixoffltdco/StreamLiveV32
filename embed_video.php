<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__ . '/includes/paid_access.php')) require_once __DIR__ . '/includes/paid_access.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$stmt = db()->prepare(
  "SELECT v.* FROM videos v WHERE v.slug = ? AND v.status = 'published'"
);
$stmt->execute([$slug]);
$video = $stmt->fetch();
if (!$video) {
  http_response_code(404);
  echo 'Not found';
  exit;
}
$user = function_exists('current_user') ? current_user() : null;
if (function_exists('paid_user_can_watch_video') && !paid_user_can_watch_video($user, $video)) {
  http_response_code(403);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><body style="background:#111;color:#eee;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">';
  echo '<div style="text-align:center;padding:20px"><p>Нужен промокод (доступ 30 дней).</p>';
  echo '<a href="/video.php?slug=' . htmlspecialchars($video['slug'], ENT_QUOTES, 'UTF-8') . '" style="color:#3ea6ff">Открыть страницу видео</a></div></body></html>';
  exit;
}
header('X-Frame-Options: ALLOWALL');
// allow embedding
header_remove('X-Frame-Options');
header('Content-Security-Policy: frame-ancestors *');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= htmlspecialchars($video['title'] ?? 'Video', ENT_QUOTES, 'UTF-8') ?></title>
<style>
html,body{margin:0;padding:0;height:100%;background:#000;overflow:hidden}
.wrap{position:fixed;inset:0}
iframe,video{width:100%;height:100%;border:0;display:block;background:#000}
</style>
</head>
<body>
<div class="wrap">
<?php if (($video['platform'] ?? '') === 'mp4'): ?>
  <video src="<?= htmlspecialchars($video['embed_url'], ENT_QUOTES, 'UTF-8') ?>" controls playsinline autoplay></video>
<?php elseif (($video['platform'] ?? '') === 'm3u8'): ?>
  <video id="v" controls playsinline></video>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
  <script>
    var src = <?= json_encode($video['embed_url']) ?>;
    var v = document.getElementById('v');
    if (window.Hls && Hls.isSupported()) { var h = new Hls(); h.loadSource(src); h.attachMedia(v); v.play(); }
    else { v.src = src; }
  </script>
<?php else: ?>
  <iframe src="<?= htmlspecialchars($video['embed_url'], ENT_QUOTES, 'UTF-8') ?>" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture"></iframe>
<?php endif; ?>
</div>
</body>
</html>
