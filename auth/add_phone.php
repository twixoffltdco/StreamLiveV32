<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();

if (!empty($__user['phone'])) redirect('/dashboard.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $phone = normalize_phone(trim($_POST['phone'] ?? ''));
  if (!$phone) {
    $error = 'Похоже на ненастоящий номер. Введите в международном формате, например +79991234567.';
  } else {
    $stmt = db()->prepare('SELECT id FROM users WHERE phone = ? AND id != ?');
    $stmt->execute([$phone, $__user['id']]);
    if ($stmt->fetch()) {
      $error = 'Этот номер уже привязан к другому аккаунту.';
    } else {
      db()->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$phone, $__user['id']]);
      flash_set('success', 'Телефон сохранён — теперь это запасной способ входа.');
      redirect('/dashboard.php');
    }
  }
}

$pageTitle = 'Укажите номер телефона';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="form-card">
    <h2>📱 Нужен номер телефона</h2>
    <p style="color:var(--text-dim);font-size:13px;line-height:1.5">
      Это запасной способ входа в аккаунт, если вы забудете почту или пароль от неё.
      SMS с кодом мы не отправляем — просто проверяем, что номер похож на настоящий.
    </p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <label>Номер телефона</label>
      <input type="tel" name="phone" required placeholder="+79991234567" autofocus>
      <button class="btn btn-primary" style="margin-top:20px;width:100%" type="submit">Сохранить и продолжить</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
