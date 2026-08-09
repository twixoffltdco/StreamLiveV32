<?php
/**
 * Своя заглушка вместо страниц InfinityFree (43/40/404/500).
 * Вызов: /error.php?c=404  или ErrorDocument
 */
$code = (int)($_GET['c'] ?? $_SERVER['REDIRECT_STATUS'] ?? 404);
if ($code < 400 || $code > 599) $code = 404;
http_response_code($code);

$titles = [
  403 => 'Доступ ограничен',
  404 => 'Страница не найдена',
  500 => 'Временный сбой',
  503 => 'Сервис недоступен',
];
$title = $titles[$code] ?? 'Ошибка ' . $code;
$msg = match (true) {
  $code === 403 => 'Нет доступа к этому разделу. Войдите под нужным аккаунтом или вернитесь на главную.',
  $code === 404 => 'Такой страницы нет или она переехала. Проверьте ссылку или откройте главную площадки.',
  $code >= 500 => 'На сервере временный сбой. Обновите страницу через минуту. Если повторяется — напишите администрации.',
  default => 'Что-то пошло не так. Попробуйте главную или повторите позже.',
};

$site = 'StreamLive';
if (is_file(__DIR__ . '/config.php')) {
  @include_once __DIR__ . '/config.php';
  if (defined('SITE_NAME')) $site = SITE_NAME;
} elseif (is_file(__DIR__ . '/includes/config.php')) {
  @include_once __DIR__ . '/includes/config.php';
  if (defined('SITE_NAME')) $site = SITE_NAME;
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title . ' — ' . $site, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  :root { --bg:#0a0a0b; --fg:#f4f4f5; --muted:#a1a1aa; --accent:#22d3ee; --card:#121214; --border:rgba(255,255,255,.08); }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    min-height: 100vh; display: grid; place-items: center;
    font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    background: var(--bg); color: var(--fg); padding: 24px;
  }
  .box {
    max-width: 420px; width: 100%;
    background: var(--card); border: 1px solid var(--border);
    border-radius: 16px; padding: 28px 24px; text-align: center;
  }
  .code { font-size: 48px; font-weight: 800; letter-spacing: -.04em; color: var(--accent); }
  h1 { font-size: 1.25rem; margin: 8px 0 10px; }
  p { color: var(--muted); font-size: 14px; line-height: 1.5; margin-bottom: 20px; }
  a {
    display: inline-block; padding: 10px 18px; border-radius: 10px;
    background: var(--accent); color: #042f2e; font-weight: 600; text-decoration: none; font-size: 14px;
  }
  a.sec { background: transparent; color: var(--muted); border: 1px solid var(--border); margin-left: 8px; }
</style>
</head>
<body>
  <div class="box">
    <div class="code"><?= (int)$code ?></div>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p>
    <div>
      <a href="/">На главную</a>
      <a class="sec" href="/admin/">Админка</a>
    </div>
  </div>
</body>
</html>
