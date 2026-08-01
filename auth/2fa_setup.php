<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/totp.php';

$tokenUserId = !empty($_REQUEST['t']) ? verify_2fa_token($_REQUEST['t']) : null;
$userId = $_SESSION['pending_2fa_user_id'] ?? $_SESSION['user_id'] ?? $tokenUserId;
if ($tokenUserId) $_SESSION['pending_2fa_user_id'] = $tokenUserId;
if (!$userId) redirect('/auth/login.php');

// Токен для скрытого поля/URL формы — переживает POST, даже если куки не сохраняются.
$formToken = $_REQUEST['t'] ?? make_2fa_token((int)$userId);

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) redirect('/auth/login.php');
if ($user['totp_enabled']) redirect('/auth/2fa_verify.php');

// Секрет генерируется один раз и сразу сохраняется (ещё выключён — totp_enabled=0),
// чтобы обновление страницы каждый раз не давало новый QR-код
if (!$user['totp_secret']) {
  $secret = Totp::generateSecret();
  db()->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([$secret, $userId]);
  $user['totp_secret'] = $secret;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrfOk = !empty($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
  $tokenOk = $tokenUserId !== null && $tokenUserId === (int)$userId;
  if (!$csrfOk && !$tokenOk) {
    $error = 'Сессия обновилась, попробуйте отправить код ещё раз.';
  } else {
    $code = $_POST['code'] ?? '';
    if (Totp::verify($user['totp_secret'], $code)) {
      db()->prepare('UPDATE users SET totp_enabled = 1 WHERE id = ?')->execute([$userId]);
      unset($_SESSION['pending_2fa_user_id']);
      login_user((int)$userId);
      flash_set('success', 'Двухфакторная аутентификация включена');
      $next = $_SESSION['login_next'] ?? '/dashboard.php';
      unset($_SESSION['login_next']);
      redirect($next);
    }
    $error = 'Неверный код. Проверьте точное время на телефоне (автоматическая синхронизация времени) и попробуйте снова.';
  }
}

$issuer = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$otpUri = Totp::provisioningUri($user['totp_secret'], $user['username'], $issuer);
$qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($otpUri);

$pageTitle = 'Настройка 2FA';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="form-card">
    <h2>Обязательная настройка 2FA</h2>
    <p style="color:var(--text-dim);font-size:13px">Без этого доступ к сайту закрыт. Отсканируйте QR-код в Google Authenticator, Authy или любом другом TOTP-приложении, затем введите текущий 6-значный код.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <div style="text-align:center;margin:16px 0"><img src="<?= e($qrImg) ?>" alt="QR-код 2FA" style="border-radius:12px;background:#fff;padding:8px"></div>
    <p style="font-size:12px;color:var(--text-dim);word-break:break-all;text-align:center">Не получается отсканировать? Введите ключ вручную: <b><?= e($user['totp_secret']) ?></b></p>
    <form method="POST" action="/auth/2fa_setup.php?t=<?= e($formToken) ?>" style="margin-top:16px">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($formToken) ?>">
      <label>Код из приложения</label>
      <input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus
             oninput="this.value = this.value.replace(/\D+/g, '').slice(0, 6)">
      <button class="btn btn-primary" style="margin-top:16px;width:100%" type="submit">Подтвердить и включить</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
