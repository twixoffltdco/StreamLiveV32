<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if ($_POST['action'] === 'update') {
    $stmt = db()->prepare(
      'UPDATE oauth_providers SET display_name=?, icon_url=?, client_id=?, client_secret=?, auth_url=?, token_url=?, profile_url=?, scope=?, enabled=? WHERE id=?'
    );
    $stmt->execute([
      trim($_POST['display_name']), trim($_POST['icon_url']), trim($_POST['client_id']), trim($_POST['client_secret']),
      trim($_POST['auth_url']), trim($_POST['token_url']), trim($_POST['profile_url']), trim($_POST['scope']),
      isset($_POST['enabled']) ? 1 : 0, (int)$_POST['id']
    ]);
    flash_set('success', 'Провайдер обновлён');
  } elseif ($_POST['action'] === 'add') {
    $stmt = db()->prepare(
      'INSERT INTO oauth_providers (name, display_name, icon_url, auth_url, token_url, profile_url, scope, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $stmt->execute([
      trim($_POST['name']), trim($_POST['display_name']), trim($_POST['icon_url']),
      trim($_POST['auth_url']), trim($_POST['token_url']), trim($_POST['profile_url']), trim($_POST['scope'])
    ]);
    flash_set('success', 'Провайдер добавлен');
  }
  redirect('/admin/oauth.php');
}
$providers = db()->query('SELECT * FROM oauth_providers ORDER BY id')->fetchAll();
?>
<h2>Соц. авторизация</h2>
<p style="color:var(--text-dim);font-size:13px">Впишите client_id/client_secret из кабинета разработчика соцсети. Включите провайдер — кнопка появится на странице входа сразу.</p>

<?php foreach ($providers as $p): ?>
<div class="form-card form-wide" style="margin:18px 0">
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <h3 style="margin-top:0"><?= e($p['display_name']) ?> <span class="status-pill <?= $p['enabled'] ? 'status-approved' : 'status-pending' ?>"><?= $p['enabled'] ? 'включён' : 'выключен' ?></span></h3>
    <label>Отображаемое имя</label>
    <input type="text" name="display_name" value="<?= e($p['display_name']) ?>">
    <label>Иконка (URL)</label>
    <input type="url" name="icon_url" value="<?= e($p['icon_url']) ?>">
    <label>Client ID</label>
    <input type="text" name="client_id" value="<?= e($p['client_id']) ?>">
    <label>Client Secret</label>
    <input type="text" name="client_secret" value="<?= e($p['client_secret']) ?>">
    <label>Auth URL</label>
    <input type="url" name="auth_url" value="<?= e($p['auth_url']) ?>">
    <label>Token URL</label>
    <input type="url" name="token_url" value="<?= e($p['token_url']) ?>">
    <label>Profile URL</label>
    <input type="url" name="profile_url" value="<?= e($p['profile_url']) ?>">
    <label>Scope</label>
    <input type="text" name="scope" value="<?= e($p['scope']) ?>">
    <label style="display:flex;align-items:center;gap:8px;margin-top:14px">
      <input type="checkbox" name="enabled" value="1" style="width:auto" <?= $p['enabled'] ? 'checked' : '' ?>> Включить провайдер
    </label>
    <button class="btn btn-primary" style="margin-top:16px" type="submit">Сохранить</button>
  </form>
</div>
<?php endforeach; ?>

<div class="form-card form-wide">
  <h3 style="margin-top:0">Добавить свой OAuth2-провайдер</h3>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label>Системное имя (латиницей, напр. discord)</label>
    <input type="text" name="name" required>
    <label>Отображаемое имя</label>
    <input type="text" name="display_name" required>
    <label>Иконка (URL)</label>
    <input type="url" name="icon_url">
    <label>Auth URL</label>
    <input type="url" name="auth_url" required>
    <label>Token URL</label>
    <input type="url" name="token_url" required>
    <label>Profile URL</label>
    <input type="url" name="profile_url" required>
    <label>Scope</label>
    <input type="text" name="scope">
    <button class="btn btn-primary" style="margin-top:16px" type="submit">Добавить провайдер</button>
  </form>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
