<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/totp.php';

// $_REQUEST['t'] ловит токен и из query-строки (GET), и из скрытого поля формы (POST) —
// не важно, докуда долетела кука сессии.
$tokenUserId = !empty($_REQUEST['t']) ? verify_2fa_token($_REQUEST['t']) : null;
$userId = $_SESSION['pending_2fa_user_id'] ?? $tokenUserId;
if ($tokenUserId) $_SESSION['pending_2fa_user_id'] = $tokenUserId; // синхронизируем сессию, если она вообще работает
if (!$userId) redirect('/auth/login.php');

// Токен для скрытого поля/URL формы — переживает и сам POST, если куки в браузере не сохраняются.
$formToken = $_REQUEST['t'] ?? make_2fa_token((int)$userId);

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user || !$user['totp_enabled']) redirect('/auth/login.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Обычная защита — сверка csrf-токена сессии. Если сессия/кука не долетела, но при
  // этом пришёл валидный подписанный токен 't' (короткоживущий, подделать нельзя без
  // секрета на сервере) — этого достаточно, чтобы считать запрос легитимным.
  $csrfOk = !empty($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
  $tokenOk = $tokenUserId !== null && $tokenUserId === (int)$userId;
  if (!$csrfOk && !$tokenOk) {
    $error = 'Сессия обновилась, попробуйте отправить код ещё раз.';
  } else {
    $code = $_POST['code'] ?? '';
    if (Totp::verify($user['totp_secret'], $code)) {
      unset($_SESSION['pending_2fa_user_id']);
      login_user((int)$userId);
      redirect('/dashboard.php');
    }
    $error = 'Неверный код. Проверьте точное время на телефоне (автоматическая синхронизация времени).';
  }
}

$pageTitle = 'Код 2FA';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="form-card">
    <h2>Введите код 2FA</h2>
    <p style="color:var(--text-dim);font-size:13px">Откройте Google Authenticator / Authy и введите текущий 6-значный код для аккаунта <b><?= e($user['username']) ?></b>.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="POST" action="/auth/2fa_verify.php?t=<?= e($formToken) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($formToken) ?>">
      <input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus
             oninput="this.value = this.value.replace(/\D+/g, '').slice(0, 6)"
             style="text-align:center;font-size:22px;letter-spacing:6px">
      <button class="btn btn-primary" style="margin-top:16px;width:100%" type="submit">Войти</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
