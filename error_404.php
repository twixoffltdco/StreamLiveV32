<?php
http_response_code(404);
$site = 'StreamLive';
try {
  if (is_file(__DIR__ . '/config/config.php')) {
    require_once __DIR__ . '/config/config.php';
    if (defined('SITE_NAME')) $site = SITE_NAME;
  }
} catch (Throwable $e) {}
?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>404 — <?= htmlspecialchars($site) ?></title>
  <style>
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      background:#0b0b0f;color:#f2f2f2;font-family:system-ui,-apple-system,sans-serif;text-align:center;padding:24px}
    .box{max-width:440px}
    .code{font-size:4rem;font-weight:800;letter-spacing:-2px;background:linear-gradient(135deg,#7c5cff,#2ee6a6);
      -webkit-background-clip:text;-webkit-text-fill-color:transparent;margin:0}
    p{color:#9aa;line-height:1.5}
    a{color:#7c5cff;text-decoration:none;font-weight:600}
    a:hover{text-decoration:underline}
  </style>
</head>
<body>
  <div class="box">
    <p class="code">404</p>
    <h1 style="margin:8px 0;font-size:1.25rem">Страница не найдена</h1>
    <p>Такой страницы нет или её перенесли. Вернитесь на главную или в каталог.</p>
    <p><a href="/">На главную</a> · <a href="/catalog">Каталог</a> · <a href="/forum">Форум</a></p>
  </div>
</body>
</html>
