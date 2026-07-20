<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ты не робот? — <?= e(defined('SITE_NAME') ? SITE_NAME : 'StreamLive') ?></title>
<meta name="robots" content="noindex, nofollow">
<style>
  html,body{margin:0;height:100%;background:#0b0b10;color:#f2f2f5;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
  .box{max-width:380px;width:100%;background:#15151d;border:1px solid #2a2a35;border-radius:16px;padding:28px;text-align:center}
  .box h1{font-size:20px;margin:0 0 8px}
  .box p{font-size:13px;color:#9a9aa8;line-height:1.5;margin:0 0 20px}
  .captcha-img{background:#0b0b10;border:1px solid #2a2a35;border-radius:10px;display:block;width:100%;height:90px;margin-bottom:14px}
  input[type=text]{width:100%;box-sizing:border-box;background:#0b0b10;border:1px solid #2a2a35;border-radius:10px;padding:12px;color:#fff;font-size:16px;text-align:center;letter-spacing:4px;text-transform:uppercase;margin-bottom:12px}
  button{width:100%;padding:12px;border:0;border-radius:10px;background:linear-gradient(135deg,#fe2c55,#ff6b8b 50%,#25f4ee);color:#0b0b10;font-weight:700;font-size:14px;cursor:pointer}
  .refresh{display:block;margin-top:10px;font-size:12px;color:#9a9aa8;cursor:pointer;text-decoration:underline;background:none;border:0;width:auto;padding:0}
  .error{color:#ff4d4f;font-size:12.5px;margin-bottom:12px}
</style>
</head>
<body>
  <div class="box">
    <h1>🤖 Ты не робот?</h1>
    <p>Мы заметили необычно много запросов с вашего адреса. Чтобы продолжить — введите код с картинки.</p>
    <?php if (!empty($_GET['bad'])): ?><div class="error">Код введён неверно, попробуйте ещё раз</div><?php endif; ?>
    <img class="captcha-img" src="/antibot_image.svg.php?_=<?= time() ?>" alt="Код с картинки" id="captcha-img">
    <form method="POST" action="/antibot_verify.php">
      <input type="hidden" name="return_to" value="<?= e($returnTo ?? '/') ?>">
      <input type="text" name="code" maxlength="5" autocomplete="off" autocapitalize="characters" placeholder="Введите код" required autofocus>
      <button type="submit">Продолжить</button>
    </form>
    <button type="button" class="refresh" onclick="document.getElementById('captcha-img').src='/antibot_image.svg.php?_='+Date.now()">Обновить код</button>
  </div>
</body>
</html>
