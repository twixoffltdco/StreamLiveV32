<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$clientId = $_GET['client_id'] ?? '';
$stmt = db()->prepare('SELECT oa.*, u.username AS owner_username FROM oauth_apps oa JOIN users u ON u.id = oa.owner_id WHERE oa.client_id = ?');
$stmt->execute([$clientId]);
$app = $stmt->fetch();

if (!$app) {
  http_response_code(400);
  die('Неизвестное приложение (client_id)');
}

// Если пользователь не авторизован — сначала логин, потом обратно сюда же
if (!current_user()) {
  $_SESSION['post_login_redirect'] = $_SERVER['REQUEST_URI'];
  redirect('/auth/login.php');
}
$__user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if (($_POST['decision'] ?? '') === 'allow') {
    $code = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO oauth_auth_codes (code, app_id, user_id, redirect_uri, expires_at) VALUES (?, ?, ?, ?, ?)')
      ->execute([$code, $app['id'], $__user['id'], $app['redirect_uri'], date('Y-m-d H:i:s', time() + 600)]);
    $sep = strpos($app['redirect_uri'], '?') !== false ? '&' : '?';
    header('Location: ' . $app['redirect_uri'] . $sep . 'code=' . urlencode($code));
    exit;
  }
  // Отказ — просто возвращаем на redirect_uri с ошибкой (стандартное поведение OAuth2)
  $sep = strpos($app['redirect_uri'], '?') !== false ? '&' : '?';
  header('Location: ' . $app['redirect_uri'] . $sep . 'error=access_denied');
  exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Открыть <?= e($app['name']) ?>? — <?= e(SITE_NAME) ?></title>
<meta name="robots" content="noindex, nofollow">
<style>
  html,body{margin:0;height:100%;background:rgba(0,0,0,.6);font-family:-apple-system,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
  .modal{max-width:400px;width:100%;background:#15151d;border:1px solid #2a2a35;border-radius:16px;padding:28px;color:#f2f2f5;text-align:center}
  .modal img{width:56px;height:56px;border-radius:14px;object-fit:cover;background:#0b0b10;margin-bottom:12px}
  .modal h1{font-size:18px;margin:0 0 6px}
  .modal p{color:#9a9aa8;font-size:13px;line-height:1.5}
  .risk{background:rgba(255,77,79,.1);border:1px solid rgba(255,77,79,.3);border-radius:10px;padding:12px;font-size:12px;color:#ff9b9d;text-align:left;margin:16px 0}
  .scope-list{text-align:left;font-size:13px;margin:14px 0;padding-left:20px;color:#f2f2f5}
  .btn-row{display:flex;gap:10px;margin-top:18px}
  button{flex:1;padding:12px;border-radius:10px;border:0;font-weight:700;font-size:14px;cursor:pointer}
  .allow{background:linear-gradient(135deg,#fe2c55,#ff6b8b 50%,#25f4ee);color:#0b0b10}
  .deny{background:#242430;color:#f2f2f5}
</style>
</head>
<body>
  <div class="modal">
    <?php if ($app['logo_url']): ?><img src="<?= e($app['logo_url']) ?>" alt=""><?php endif; ?>
    <h1>Открыть «<?= e($app['name']) ?>»?</h1>
    <p><?= e($app['description'] ?: 'Стороннее приложение, зарегистрированное на нашей платформе.') ?></p>
    <p style="font-size:12px">Разработчик: <?= e($app['owner_username']) ?></p>
    <ul class="scope-list">
      <li>Ваш логин и аватар</li>
      <li>Публичный ID аккаунта</li>
    </ul>
    <div class="risk">⚠️ Вы передаёте эти данные стороннему приложению, которое мы не контролируем.
      Открывайте, только если доверяете разработчику.</div>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="btn-row">
        <button class="deny" type="submit" name="decision" value="deny">Отмена</button>
        <button class="allow" type="submit" name="decision" value="allow">Да, согласен на риск</button>
      </div>
    </form>
  </div>
</body>
</html>
