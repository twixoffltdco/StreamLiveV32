<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

$newSecret = null; // показываем секрет только один раз, сразу после создания

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $name = trim($_POST['name'] ?? '');
    $redirectUri = trim($_POST['redirect_uri'] ?? '');
    $serviceUrl = trim($_POST['service_url'] ?? '');
    if (mb_strlen($name) < 2 || !filter_var($redirectUri, FILTER_VALIDATE_URL)) {
      flash_set('error', 'Укажите название и корректный redirect_uri (https://...)');
      redirect('/developers.php');
    }
    $clientId = bin2hex(random_bytes(12));
    $secret = bin2hex(random_bytes(24));
    db()->prepare(
      'INSERT INTO oauth_apps (owner_id, name, description, redirect_uri, service_url, client_id, client_secret_hash) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
      $__user['id'], $name, trim($_POST['description'] ?? ''), $redirectUri, $serviceUrl ?: null,
      $clientId, password_hash($secret, PASSWORD_DEFAULT),
    ]);
    $newSecret = $secret; // покажем ниже, один раз
    $newClientId = $clientId;
    flash_set('success', 'Приложение создано! Сохраните client_secret прямо сейчас — второй раз мы его не покажем.');
  } elseif ($action === 'toggle_public') {
    db()->prepare('UPDATE oauth_apps SET is_public_service = 1 - is_public_service WHERE id = ? AND owner_id = ?')
      ->execute([(int)$_POST['app_id'], $__user['id']]);
    redirect('/developers.php');
  } elseif ($action === 'delete') {
    db()->prepare('DELETE FROM oauth_apps WHERE id = ? AND owner_id = ?')->execute([(int)$_POST['app_id'], $__user['id']]);
    flash_set('success', 'Приложение удалено');
    redirect('/developers.php');
  } elseif ($action === 'regenerate_secret') {
    $appId = (int)($_POST['app_id'] ?? 0);
    $stmt = db()->prepare('SELECT client_id FROM oauth_apps WHERE id = ? AND owner_id = ?');
    $stmt->execute([$appId, $__user['id']]);
    if ($stmt->fetch()) {
      $secret = bin2hex(random_bytes(24));
      db()->prepare('UPDATE oauth_apps SET client_secret_hash = ? WHERE id = ?')->execute([password_hash($secret, PASSWORD_DEFAULT), $appId]);
      $newSecret = $secret;
      $newClientId = $stmt->fetchColumn();
    }
  }
}

$stmt = db()->prepare('SELECT * FROM oauth_apps WHERE owner_id = ? ORDER BY id DESC');
$stmt->execute([$__user['id']]);
$apps = $stmt->fetchAll();

$pageTitle = 'Консоль разработчика';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h2 style="margin:24px 0 6px">🛠 Консоль разработчика</h2>
  <p style="color:var(--text-dim);font-size:13px;max-width:640px">
    Зарегистрируйте своё приложение и предложите пользователям вход "через аккаунт <?= e(SITE_NAME) ?>" —
    как вход через ВКонтакте/Google, только через нашу платформу. Подключение — стандартный OAuth2
    (authorization code): <code>/oauth2/authorize.php</code> → <code>/oauth2/token.php</code> → <code>/oauth2/userinfo.php</code>.
  </p>

  <?php if ($newSecret): ?>
    <div class="alert alert-success" style="margin:16px 0">
      <b>Сохраните прямо сейчас — второй раз не покажем:</b><br>
      client_id: <code><?= e($newClientId) ?></code><br>
      client_secret: <code><?= e($newSecret) ?></code>
    </div>
  <?php endif; ?>

  <div class="form-card form-wide" style="margin:20px 0">
    <h3>Новое приложение</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <label>Название</label>
      <input type="text" name="name" required maxlength="150">
      <label style="margin-top:10px">Описание (покажем на экране согласия)</label>
      <textarea name="description" maxlength="500" rows="2"></textarea>
      <label style="margin-top:10px">Redirect URI (куда вернуть пользователя с кодом)</label>
      <input type="url" name="redirect_uri" required placeholder="https://ваш-сервис.ru/callback">
      <label style="margin-top:10px">URL сервиса (не обязательно — чтобы показать в каталоге «Сервисы»)</label>
      <input type="url" name="service_url" placeholder="https://ваш-сервис.ru">
      <button class="btn btn-primary" style="margin-top:16px" type="submit">Зарегистрировать приложение</button>
    </form>
  </div>

  <h3 style="margin:24px 0 12px">Мои приложения</h3>
  <?php foreach ($apps as $a): ?>
    <div class="form-card form-wide" style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
          <b><?= e($a['name']) ?></b>
          <?php if ($a['is_public_service']): ?><span class="status-pill status-approved" style="margin-left:6px">в каталоге Сервисы</span><?php endif; ?>
          <div style="color:var(--text-dim);font-size:12.5px;margin-top:4px">client_id: <code><?= e($a['client_id']) ?></code></div>
          <div style="color:var(--text-dim);font-size:12.5px">redirect_uri: <?= e($a['redirect_uri']) ?></div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-self:start">
          <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_public"><input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit"><?= $a['is_public_service'] ? 'Убрать из каталога' : 'В каталог Сервисы' ?></button>
          </form>
          <form method="POST" onsubmit="return confirm('Старый client_secret перестанет работать. Продолжить?')"><?= csrf_field() ?><input type="hidden" name="action" value="regenerate_secret"><input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit">Новый secret</button>
          </form>
          <form method="POST" onsubmit="return confirm('Удалить приложение безвозвратно?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
            <button class="btn btn-outline btn-sm" type="submit" style="color:var(--danger);border-color:var(--danger)">Удалить</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$apps): ?><p style="color:var(--text-dim)">Пока нет приложений.</p><?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
