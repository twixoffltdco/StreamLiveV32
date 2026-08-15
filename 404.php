<?php
http_response_code(404);
$pageTitle = 'Страница не найдена';
try {
  if (is_file(__DIR__ . '/includes/functions.php')) require_once __DIR__ . '/includes/functions.php';
  if (is_file(__DIR__ . '/includes/auth.php')) require_once __DIR__ . '/includes/auth.php';
  if (is_file(__DIR__ . '/includes/header.php')) require_once __DIR__ . '/includes/header.php';
} catch (Throwable $e) {
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404</title></head><body>';
}
?>
<div class="container" style="padding:40px 16px;text-align:center">
  <h1 style="font-size:28px;margin:0 0 10px">404</h1>
  <p style="opacity:.7;margin:0 0 20px">Страница не найдена или временно недоступна.</p>
  <p>
    <a class="btn btn-primary" href="/">На главную</a>
    <a class="btn btn-outline" href="/forum.php">Форум</a>
    <a class="btn btn-outline" href="/videos.php">Видео</a>
  </p>
</div>
<?php
try { if (is_file(__DIR__ . '/includes/footer.php')) require_once __DIR__ . '/includes/footer.php'; } catch (Throwable $e) { echo '</body></html>'; }
