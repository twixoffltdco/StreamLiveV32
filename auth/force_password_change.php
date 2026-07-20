<?php
require_once __DIR__ . '/../includes/auth.php';
$__user = require_login();

if (!$__user['must_change_password']) { redirect('/dashboard.php'); }

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $new = (string)($_POST['new_password'] ?? '');
  $confirm = (string)($_POST['confirm_password'] ?? '');
  if (mb_strlen($new) < 8) {
    $error = 'Пароль должен быть не короче 8 символов';
  } elseif ($new !== $confirm) {
    $error = 'Пароли не совпадают';
  } else {
    $hash = password_hash($new, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
      ->execute([$hash, $__user['id']]);
    flash_set('success', 'Пароль изменён');
    redirect('/dashboard.php');
  }
}

$pageTitle = 'Смена пароля';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="max-width:420px">
  <div class="form-card">
    <h2>Нужно сменить пароль</h2>
    <p style="color:var(--text-dim);font-size:13px">Администратор сбросил ваш пароль. Задайте новый, прежде чем продолжить пользоваться аккаунтом.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <label>Новый пароль</label>
      <input type="password" name="new_password" required minlength="8" style="width:100%;padding:10px;margin:8px 0">
      <label>Повторите пароль</label>
      <input type="password" name="confirm_password" required minlength="8" style="width:100%;padding:10px;margin:8px 0">
      <button type="submit" class="btn btn-primary" style="width:100%">Сменить пароль</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
