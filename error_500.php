<?php
http_response_code(500);
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
  <title>Ошибка — <?= htmlspecialchars($site) ?></title>
  <style>
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      background:#0b0b0f;color:#f2f2f2;font-family:system-ui,sans-serif;text-align:center;padding:24px}
    .box{max-width:440px}h1{font-size:1.3rem}p{color:#9aa;line-height:1.5}
    a{color:#2ee6a6;text-decoration:none;font-weight:600}
  </style>
</head>
<body>
  <div class="box">
    <h1>Что-то сломалось</h1>
    <p>Сервер не смог обработать запрос. Попробуйте обновить страницу. Если ошибка повторяется — напишите в поддержку / Telegram платформы.</p>
    <p><a href="/">На главную</a></p>
  </div>
</body>
</html>
