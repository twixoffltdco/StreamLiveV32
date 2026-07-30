<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

// Self-healing — на случай, если миграция 034 ещё не залита руками на хостинге: пробуем
// один раз за время жизни процесса, как везде в этом проекте.
function username_history_ensure_schema(): void {
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS username_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        old_username VARCHAR(64) NOT NULL,
        new_username VARCHAR(64) NOT NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (!table_column_exists('users', 'username_changed_at')) {
      db()->exec('ALTER TABLE users ADD COLUMN username_changed_at DATETIME DEFAULT NULL');
    }
  } catch (\Throwable $e) { /* нет прав CREATE/ALTER — залей sql/migrations/034_username_history.sql руками */ }
}
username_history_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'change_password') {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['new_password_confirm'] ?? '');
    if (!password_verify($current, $__user['password_hash'] ?? '')) {
      flash_set('error', 'Текущий пароль указан неверно');
    } elseif (mb_strlen($new) < 8) {
      flash_set('error', 'Новый пароль должен быть не короче 8 символов');
    } elseif ($new !== $confirm) {
      flash_set('error', 'Пароли не совпадают');
    } else {
      db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $__user['id']]);
      flash_set('success', 'Пароль изменён');
    }
    redirect('/account_settings.php');
  }

  if ($action === 'change_username') {
    $newUsername = trim((string)($_POST['new_username'] ?? ''));

    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $newUsername)) {
      flash_set('error', 'Ник: 3-32 символа, только латиница/цифры/подчёркивание');
    } elseif ($newUsername === $__user['username']) {
      flash_set('error', 'Это и есть ваш текущий ник');
    } elseif (!empty($__user['username_changed_at']) && (time() - strtotime($__user['username_changed_at'])) < 30 * 86400) {
      $daysLeft = 30 - (int)floor((time() - strtotime($__user['username_changed_at'])) / 86400);
      flash_set('error', "Ник можно менять раз в 30 дней — следующая смена через {$daysLeft} дн.");
    } else {
      $check = db()->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
      $check->execute([$newUsername, $__user['id']]);
      if ($check->fetch()) {
        flash_set('error', 'Этот ник уже занят');
      } else {
        $oldUsername = $__user['username'];
        db()->prepare('UPDATE users SET username = ?, username_changed_at = NOW() WHERE id = ?')
          ->execute([$newUsername, $__user['id']]);
        try {
          db()->prepare('INSERT INTO username_history (user_id, old_username, new_username) VALUES (?, ?, ?)')
            ->execute([$__user['id'], $oldUsername, $newUsername]);
        } catch (\Throwable $e) { /* история не сохранилась (нет таблицы) — сама смена ника всё равно применилась */ }
        flash_set('success', 'Ник изменён на ' . $newUsername);
      }
    }
    redirect('/account_settings.php');
  }
}

$history = [];
try {
  $stmt = db()->prepare('SELECT old_username, new_username, changed_at FROM username_history WHERE user_id = ? ORDER BY id DESC LIMIT 20');
  $stmt->execute([$__user['id']]);
  $history = $stmt->fetchAll();
} catch (\Throwable $e) { }

$pageTitle = 'Настройки аккаунта';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:640px">
  <h1 style="margin:24px 0 16px">⚙️ Настройки аккаунта</h1>

  <div class="form-card form-wide" style="margin-bottom:20px">
    <h3 style="margin-top:0">Сменить пароль</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <label>Текущий пароль</label>
      <input type="password" name="current_password" required autocomplete="current-password">
      <label>Новый пароль</label>
      <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
      <label>Повторите новый пароль</label>
      <input type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password">
      <button class="btn btn-primary" type="submit" style="margin-top:10px">Сменить пароль</button>
    </form>
  </div>

  <div class="form-card form-wide">
    <h3 style="margin-top:0">Сменить ник</h3>
    <p style="font-size:12.5px;color:var(--text-dim)">Текущий ник: <b><?= e($__user['username']) ?></b>. Менять можно не чаще раза в 30 дней — история смен сохраняется навсегда, даже если поменяете ник ещё раз.</p>
    <form method="POST" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_username">
      <div style="flex:1;min-width:200px">
        <label>Новый ник</label>
        <input type="text" name="new_username" pattern="[a-zA-Z0-9_]{3,32}" required placeholder="только латиница, цифры, _">
      </div>
      <button class="btn btn-primary" type="submit">Сменить</button>
    </form>
    <?php if ($history): ?>
      <div style="margin-top:14px">
        <b style="font-size:12.5px">История ников</b>
        <ul style="font-size:12.5px;color:var(--text-dim);margin:6px 0 0;padding-left:18px">
          <?php foreach ($history as $h): ?>
            <li><?= e($h['old_username']) ?> → <?= e($h['new_username']) ?> · <?= e($h['changed_at']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  <div class="form-card form-wide" style="margin-top:20px">
    <h3 style="margin-top:0">API-ключ</h3>
    <p style="font-size:12.5px;color:var(--text-dim)">
      С ключом ваши запросы к API идут без троттлинга по нагрузке и не блокируются даже во
      время защиты от атаки. Без ключа доступ зависит от текущей нагрузки сайта. Ключ сам
      обновляется раз в 30 дней — просто зайдите на эту страницу ещё раз после истечения.
    </p>
    <?php
    require_once __DIR__ . '/includes/api_auth.php';
    $apiKey = api_key_get_or_rotate((int)$__user['id']);
    $daysLeft = api_key_days_until_rotation((int)$__user['id']);
    ?>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="text" readonly value="<?= e($apiKey) ?>" style="flex:1;min-width:260px;font-family:monospace;font-size:12.5px" onclick="this.select()">
      <button type="button" class="btn btn-outline btn-sm" onclick="navigator.clipboard.writeText('<?= e($apiKey) ?>');this.textContent='Скопировано!'">Скопировать</button>
    </div>
    <p style="font-size:11.5px;color:var(--text-dim);margin-top:6px">
      До автоматической ротации: <?= $daysLeft !== null ? (int)$daysLeft . ' дн.' : '—' ?>.
      Никому не показывайте этот ключ и не публикуйте его в открытом репозитории — если он
      попадёт в открытый доступ, мы это заметим (по количеству разных адресов, с которых он
      используется) и предупредим при следующем запросе.
    </p>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
