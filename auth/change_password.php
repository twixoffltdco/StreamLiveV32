<?php
$pageTitle = 'Смена пароля';
require_once __DIR__ . '/../includes/header.php';
$user = require_login();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $current = (string)($_POST['current_password'] ?? '');
  $new = (string)($_POST['new_password'] ?? '');
  $confirm = (string)($_POST['confirm_password'] ?? '');

  if (empty($user['password_hash'])) {
    $error = 'У аккаунта нет локального пароля. Обратитесь к администратору для выдачи временного пароля.';
  } elseif (!password_verify($current, $user['password_hash'])) {
    $error = 'Текущий пароль неверный.';
  } elseif (mb_strlen($new) < 8) {
    $error = 'Новый пароль должен быть минимум 8 символов.';
  } elseif ($new !== $confirm) {
    $error = 'Пароли не совпадают.';
  } else {
    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
      ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
    flash_set('success', 'Пароль обновлён.');
    redirect('/dashboard.php');
  }
}
?>
<div class="container" style="max-width:560px">
  <div class="form-card">
    <h2>Смена пароля</h2>
    <p style="color:var(--text-dim);font-size:13px">Меняйте пароль сами без админа. После смены все новые входы будут только по новому паролю.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <label>Текущий пароль</label>
      <input type="password" name="current_password" required autocomplete="current-password" style="width:100%;padding:10px;margin:8px 0">
      <label>Новый пароль</label>
      <input type="password" name="new_password" required minlength="8" autocomplete="new-password" style="width:100%;padding:10px;margin:8px 0">
      <label>Повторите новый пароль</label>
      <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" style="width:100%;padding:10px;margin:8px 0">
      <button class="btn btn-primary" type="submit" style="margin-top:12px">Сменить пароль</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
