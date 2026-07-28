<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Проверка браузера — <?= e(defined('SITE_NAME') ? SITE_NAME : 'StreamLive') ?></title>
<meta name="robots" content="noindex, nofollow">
<style>
  html,body{margin:0;height:100%;background:#0b0b10;color:#f2f2f5;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
  .box{max-width:380px;width:100%;background:#15151d;border:1px solid #2a2a35;border-radius:16px;padding:32px 28px;text-align:center}
  .box h1{font-size:18px;margin:0 0 8px}
  .box p{font-size:13px;color:#9a9aa8;line-height:1.5;margin:0}
  .spinner{width:32px;height:32px;border-radius:50%;border:3px solid #2a2a35;border-top-color:#fe2c55;margin:0 auto 16px;animation:sp .8s linear infinite}
  @keyframes sp{to{transform:rotate(360deg)}}
  noscript p{color:#ff4d4f;margin-top:12px}
</style>
</head>
<body>
  <div class="box">
    <div class="spinner"></div>
    <h1>Проверяем ваш браузер…</h1>
    <p>Сайт сейчас видит необычно высокую нагрузку — это займёт секунду, дальше вы попадёте на нужную страницу автоматически.</p>
    <noscript><p>Нужен включённый JavaScript, чтобы продолжить.</p></noscript>
  </div>
  <script>
    document.cookie = "sl_attack_pass=<?= e($token) ?>; path=/; max-age=3600; samesite=lax<?= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '; secure' : '' ?>";
    setTimeout(function () { location.replace(<?= json_encode($returnTo) ?>); }, 700);
  </script>
</body>
</html>
