<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/github_api.php';
$__user = require_login();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'connect') {
    $token = trim($_POST['token'] ?? '');
    if ($token === '') {
      $error = 'Вставьте токен';
    } else {
      $ghUser = github_verify_token($token);
      if (!$ghUser || empty($ghUser['login'])) {
        $error = 'Токен не подошёл. Проверьте: создан ли он на github.com/settings/tokens и есть ли у него право чтения репозиториев (repo или public_repo для classic-токена, "Contents: Read-only" для fine-grained).';
      } else {
        [$cipher, $iv] = encrypt_secret($token);
        $enc = $cipher . ':' . $iv;
        db()->prepare(
          'INSERT INTO github_connections (user_id, github_username, access_token_enc) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE github_username = VALUES(github_username), access_token_enc = VALUES(access_token_enc), connected_at = NOW()'
        )->execute([$__user['id'], $ghUser['login'], $enc]);
        flash_set('success', 'GitHub-аккаунт @' . $ghUser['login'] . ' подключён');
        redirect('/github_deploy.php');
      }
    }
  } elseif ($action === 'disconnect') {
    db()->prepare('DELETE FROM github_connections WHERE user_id = ?')->execute([$__user['id']]);
    flash_set('success', 'GitHub отключён');
    redirect('/github_connect.php');
  }
}

$stmt = db()->prepare('SELECT github_username, connected_at FROM github_connections WHERE user_id = ?');
$stmt->execute([$__user['id']]);
$connection = $stmt->fetch();

$pageTitle = 'Подключить GitHub';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:640px">
  <h1>Подключение GitHub</h1>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($connection): ?>
    <div class="alert alert-success">Подключён аккаунт: <b>@<?= e($connection['github_username']) ?></b> (с <?= e(date('d.m.Y', strtotime($connection['connected_at']))) ?>)</div>
    <a href="/github_deploy.php" class="btn btn-primary">Выбрать репозиторий и выложить сервис →</a>
    <form method="POST" style="margin-top:20px" onsubmit="return confirm('Отключить GitHub? Уже выложенные сервисы продолжат работать.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="disconnect">
      <button type="submit" class="btn btn-outline" style="color:var(--danger);border-color:var(--danger)">Отключить GitHub</button>
    </form>
  <?php else: ?>
    <p style="color:var(--text-dim)">
      Создайте токен на <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">github.com/settings/tokens</a> →
      "Generate new token (classic)" → права <code>repo</code> (или <code>public_repo</code>, если репозитории публичные) → скопируйте и вставьте сюда.
      Токен шифруется перед сохранением и используется только для чтения списка репозиториев и скачивания кода при деплое.
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="connect">
      <input type="text" name="token" placeholder="ghp_..." required style="width:100%;padding:10px;margin:10px 0;font-family:monospace">
      <button type="submit" class="btn btn-primary">Подключить</button>
    </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
