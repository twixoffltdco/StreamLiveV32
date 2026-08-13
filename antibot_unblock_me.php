<?php
/**
 * С телефона: открыть /antibot_unblock_me.php
 * Снимает блок/счётчик для ТЕКУЩЕГО IP (гости).
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/antibot.php';

header('Content-Type: text/html; charset=utf-8');

$ip = antibot_client_ip();
$msg = 'OK';
try {
  antibot_ensure_table();
  $pdo = db();
  $pdo->prepare(
    'UPDATE antibot_ip_log SET request_count = 0, blocked_until = NULL, captcha_pass_until = DATE_ADD(NOW(), INTERVAL 24 HOUR),
     captcha_code = NULL, captcha_expires = NULL WHERE ip = ?'
  )->execute([$ip]);
  // если строки не было — insert pass
  $pdo->prepare(
    'INSERT IGNORE INTO antibot_ip_log (ip, request_count, window_start, captcha_pass_until) VALUES (?, 0, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))'
  )->execute([$ip]);
  $msg = 'Доступ с вашего IP разблокирован на 24 часа.';
} catch (Throwable $e) {
  $msg = 'Ошибка: ' . $e->getMessage();
}

// JS pass cookie как после челленджа
if (function_exists('antibot_js_token')) {
  $tok = antibot_js_token($ip);
  setcookie('sl_js_pass', $tok, [
    'expires' => time() + 86400,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => false,
    'samesite' => 'Lax',
  ]);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Разблокировка</title>
  <style>
    body{font-family:system-ui,sans-serif;background:#0b0b0f;color:#eee;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:20px;text-align:center}
    a{color:#7c5cff;font-weight:600}
  </style>
</head>
<body>
  <div>
    <h1>✅ <?= htmlspecialchars($msg) ?></h1>
    <p>IP: <code><?= htmlspecialchars($ip) ?></code></p>
    <p><a href="/">На главную</a></p>
  </div>
</body>
</html>
