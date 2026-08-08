<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/oauth.php';

$u = current_user();
if (!$u || ($u['role'] ?? '') !== 'admin') {
  http_response_code(403);
  echo 'Только админ';
  exit;
}

oauth_ensure_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) csrf_verify();
  $action = (string)($_POST['action'] ?? 'save');

  if ($action === 'preset') {
    $key = (string)($_POST['preset'] ?? '');
    $presets = oauth_provider_presets();
    if (isset($presets[$key])) {
      $p = $presets[$key];
      try {
        $st = db()->prepare('SELECT id FROM oauth_providers WHERE name = ?');
        $st->execute([$p['name']]);
        if ($st->fetch()) {
          flash_set('error', 'Провайдер «' . $p['name'] . '» уже есть — заполните Client ID/Secret ниже');
        } else {
          db()->prepare(
            'INSERT INTO oauth_providers (name, display_name, icon_url, auth_url, token_url, profile_url, scope, enabled, client_id, client_secret)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, "", "")'
          )->execute([
            $p['name'], $p['display_name'], $p['icon_url'],
            $p['auth_url'], $p['token_url'], $p['profile_url'], $p['scope'],
          ]);
          flash_set('success', 'Добавлен пресет «' . $p['display_name'] . '». Вставьте Client ID и Secret, включите.');
        }
      } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
      }
    }
  } elseif ($action === 'save' && !empty($_POST['id'])) {
    db()->prepare(
      'UPDATE oauth_providers SET display_name=?, icon_url=?, client_id=?, client_secret=?, auth_url=?, token_url=?, profile_url=?, scope=?, enabled=? WHERE id=?'
    )->execute([
      trim($_POST['display_name'] ?? ''),
      trim($_POST['icon_url'] ?? ''),
      trim($_POST['client_id'] ?? ''),
      trim($_POST['client_secret'] ?? ''),
      trim($_POST['auth_url'] ?? ''),
      trim($_POST['token_url'] ?? ''),
      trim($_POST['profile_url'] ?? ''),
      trim($_POST['scope'] ?? ''),
      !empty($_POST['enabled']) ? 1 : 0,
      (int)$_POST['id'],
    ]);
    flash_set('success', 'Сохранено');
  } elseif ($action === 'add') {
    db()->prepare(
      'INSERT INTO oauth_providers (name, display_name, icon_url, auth_url, token_url, profile_url, scope, enabled)
       VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
    )->execute([
      preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['name'] ?? '')),
      trim($_POST['display_name'] ?? ''),
      trim($_POST['icon_url'] ?? ''),
      trim($_POST['auth_url'] ?? ''),
      trim($_POST['token_url'] ?? ''),
      trim($_POST['profile_url'] ?? ''),
      trim($_POST['scope'] ?? ''),
    ]);
    flash_set('success', 'Провайдер добавлен');
  }
  redirect('/admin/oauth.php');
}

$providers = [];
try {
  $providers = db()->query('SELECT * FROM oauth_providers ORDER BY id')->fetchAll() ?: [];
} catch (Throwable $e) {}

$callbackBase = rtrim(SITE_URL, '/') . '/auth/oauth_callback.php?provider=';
$pageTitle = 'Соц. авторизация';
require_once __DIR__ . '/_layout_start.php';
?>
<h2>Соц. авторизация (OAuth)</h2>
<p style="opacity:.8;max-width:720px">
  Callback URL для каждого провайдера (укажи в кабинете GitHub / Яндекс / VK):<br>
  <code><?= e($callbackBase) ?>ИМЯ</code>
  — например <code><?= e($callbackBase) ?>github</code>
</p>

<div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 20px">
  <?php foreach (oauth_provider_presets() as $key => $p): ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="preset">
      <input type="hidden" name="preset" value="<?= e($key) ?>">
      <button class="btn btn-outline btn-sm" type="submit">+ <?= e($p['display_name']) ?></button>
    </form>
  <?php endforeach; ?>
</div>

<?php if (function_exists('flash_get')): foreach (flash_get() as $t => $m): ?>
  <div class="alert alert-<?= e($t) ?>"><?= e($m) ?></div>
<?php endforeach; endif; ?>

<?php foreach ($providers as $p): ?>
  <form method="post" class="form-card" style="margin-bottom:16px;max-width:640px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <h3 style="margin-top:0"><?= e($p['display_name']) ?> <small style="opacity:.6">(<?= e($p['name']) ?>)</small></h3>
    <p style="font-size:12px;opacity:.7">Redirect: <code><?= e($callbackBase . $p['name']) ?></code></p>
    <label>Отображаемое имя</label>
    <input type="text" name="display_name" value="<?= e($p['display_name']) ?>">
    <label>Иконка (URL)</label>
    <input type="url" name="icon_url" value="<?= e($p['icon_url'] ?? '') ?>">
    <label>Client ID</label>
    <input type="text" name="client_id" value="<?= e($p['client_id'] ?? '') ?>" autocomplete="off">
    <label>Client Secret</label>
    <input type="password" name="client_secret" value="<?= e($p['client_secret'] ?? '') ?>" autocomplete="new-password">
    <label>Auth URL</label>
    <input type="url" name="auth_url" value="<?= e($p['auth_url']) ?>">
    <label>Token URL</label>
    <input type="url" name="token_url" value="<?= e($p['token_url']) ?>">
    <label>Profile URL</label>
    <input type="url" name="profile_url" value="<?= e($p['profile_url']) ?>">
    <label>Scope</label>
    <input type="text" name="scope" value="<?= e($p['scope'] ?? '') ?>">
    <label style="display:flex;align-items:center;gap:8px;margin-top:10px">
      <input type="checkbox" name="enabled" value="1" <?= !empty($p['enabled']) ? 'checked' : '' ?>>
      Включён (кнопка на странице входа)
    </label>
    <button class="btn btn-primary" style="margin-top:12px" type="submit">Сохранить</button>
  </form>
<?php endforeach; ?>

<details style="margin-top:24px;max-width:640px">
  <summary>Добавить провайдер вручную</summary>
  <form method="post" class="form-card" style="margin-top:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label>name (латиница)</label><input name="name" required placeholder="github">
    <label>Отображаемое имя</label><input name="display_name" required>
    <label>Иконка</label><input name="icon_url" type="url">
    <label>Auth URL</label><input name="auth_url" type="url" required>
    <label>Token URL</label><input name="token_url" type="url" required>
    <label>Profile URL</label><input name="profile_url" type="url" required>
    <label>Scope</label><input name="scope">
    <button class="btn btn-primary" type="submit">Добавить</button>
  </form>
</details>

<div class="form-card" style="margin-top:24px;max-width:640px;font-size:13px;opacity:.85">
  <h3>GitHub — как включить</h3>
  <ol>
    <li>GitHub → Settings → Developer settings → <b>OAuth Apps</b> → New</li>
    <li>Homepage: URL площадки</li>
    <li>Authorization callback URL: <code><?= e($callbackBase) ?>github</code></li>
    <li>Скопируй Client ID и сгенерируй Client Secret</li>
    <li>Здесь: кнопка «+ GitHub» → вставь ID/Secret → включи</li>
  </ol>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
