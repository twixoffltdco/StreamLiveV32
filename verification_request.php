<?php
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

if (!empty($__user['is_verified'])) {
  flash_set('success', 'У вас уже есть галочка «доверенный»');
  redirect('/dashboard.php');
}

$stmt = db()->prepare("SELECT * FROM verification_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$__user['id']]);
$existing = $stmt->fetch();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$existing || $existing['status'] !== 'pending')) {
  csrf_verify();
  $realName = trim(mb_substr($_POST['real_name'] ?? '', 0, 150));
  $reason = trim(mb_substr($_POST['reason'] ?? '', 0, 2000));
  if ($realName === '' || $reason === '') {
    $error = 'Заполните оба поля';
  } else {
    db()->prepare('INSERT INTO verification_requests (user_id, real_name, reason) VALUES (?, ?, ?)')
      ->execute([$__user['id'], $realName, $reason]);
    flash_set('success', 'Заявка отправлена, ждите решения модератора');
    redirect('/verification_request.php');
  }
}

$pageTitle = 'Заявка на галочку "доверенный"';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:500px">
  <h1>Заявка на галочку «доверенный»</h1>
  <p style="color:var(--text-dim);font-size:13px">
    Даёт: безлимитный деплой сервисов без автостопа через 30 дней, вставку прямых
    ссылок на видео (m3u8/mp4/file://) без строгой проверки в управлении каналом.
  </p>

  <?php if ($existing && $existing['status'] === 'pending'): ?>
    <div class="alert alert-success">Заявка на рассмотрении с <?= e($existing['created_at']) ?></div>
  <?php elseif ($existing && $existing['status'] === 'rejected'): ?>
    <div class="alert alert-error">Отклонена<?= $existing['review_note'] ? ': ' . e($existing['review_note']) : '' ?>. Можно подать заново.</div>
  <?php endif; ?>

  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <?php if (!$existing || $existing['status'] !== 'pending'): ?>
    <form method="POST">
      <?= csrf_field() ?>
      <label>Как вас зовут</label>
      <input type="text" name="real_name" required maxlength="150" style="width:100%;padding:10px;margin:8px 0">
      <label>Зачем вам галочка</label>
      <textarea name="reason" required rows="5" maxlength="2000" style="width:100%;padding:10px;margin:8px 0"></textarea>
      <button type="submit" class="btn btn-primary">Отправить заявку</button>
    </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
