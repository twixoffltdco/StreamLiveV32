<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
require_once __DIR__ . '/../includes/self_deploy.php';

try {
  db()->exec(
    "CREATE TABLE IF NOT EXISTS self_deploy_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      commit_sha VARCHAR(64) DEFAULT NULL,
      commit_message VARCHAR(500) DEFAULT NULL,
      status ENUM('success','failed') NOT NULL,
      error_message VARCHAR(1000) DEFAULT NULL,
      files_updated INT DEFAULT NULL,
      duration_ms INT DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
} catch (\Throwable $e) { /* нет прав CREATE — залей sql/migrations/028_self_deploy.sql руками через phpMyAdmin */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if (($_POST['action'] ?? '') === 'save_settings') {
    set_setting('self_deploy_repo_owner', trim($_POST['repo_owner'] ?? ''));
    set_setting('self_deploy_repo_name', trim($_POST['repo_name'] ?? ''));
    set_setting('self_deploy_branch', trim($_POST['branch'] ?? '') ?: 'master');
    $newSecret = trim($_POST['webhook_secret'] ?? '');
    if ($newSecret !== '') set_setting('self_deploy_webhook_secret', $newSecret);
    flash_set('success', 'Настройки автодеплоя сохранены');
  } elseif (($_POST['action'] ?? '') === 'generate_secret') {
    set_setting('self_deploy_webhook_secret', bin2hex(random_bytes(24)));
    flash_set('success', 'Новый секрет сгенерирован — обнови его и в настройках вебхука на GitHub, старый теперь недействителен');
  } elseif (($_POST['action'] ?? '') === 'deploy_now') {
    $branch = get_setting('self_deploy_branch', 'master');
    $result = self_deploy_run($branch, null, 'Ручной запуск из админки');
    flash_set($result['success'] ? 'success' : 'error', $result['success']
      ? "Готово — обновлено файлов: {$result['files_updated']} за {$result['duration_ms']} мс"
      : 'Ошибка: ' . $result['error']);
  }
  redirect('/admin/auto_deploy.php');
}

$owner = get_setting('self_deploy_repo_owner', '');
$repo = get_setting('self_deploy_repo_name', '');
$branch = get_setting('self_deploy_branch', 'master');
$secret = get_setting('self_deploy_webhook_secret', '');
$webhookUrl = rtrim(SITE_URL, '/') . '/github_self_update_webhook.php';

$log = [];
try { $log = db()->query('SELECT * FROM self_deploy_log ORDER BY id DESC LIMIT 20')->fetchAll(); } catch (\Throwable $e) { }
?>
<h2>Автодеплой с GitHub</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:640px">
  При пуше в указанную ветку сайт сам скачает свежий код и разложит поверх себя — без SSH и
  без git на сервере. НЕ трогает: <code>config/config.php</code>, <code>storage/</code>,
  <code>services_data/</code>, <code>.htaccess</code> — эти файлы/папки безопасны, обновляется
  только код проекта.
</p>

<div class="form-card form-wide">
  <h3 style="margin-top:0">1. Репозиторий</h3>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    <label>Владелец репозитория (username/организация на GitHub)</label>
    <input type="text" name="repo_owner" value="<?= e($owner) ?>" placeholder="OinkTechLtd" required>
    <label>Название репозитория</label>
    <input type="text" name="repo_name" value="<?= e($repo) ?>" placeholder="streamlivev20" required>
    <label>Ветка для автодеплоя</label>
    <input type="text" name="branch" value="<?= e($branch) ?>" placeholder="master" required>
    <label>Секрет вебхука <?= $secret ? '(уже задан — оставь поле пустым, чтобы не менять)' : '(сгенерируй кнопкой ниже)' ?></label>
    <input type="text" name="webhook_secret" placeholder="<?= $secret ? '••••••••••••••••••••' : 'нажми «Сгенерировать» ниже' ?>">
    <button class="btn btn-primary" type="submit" style="margin-top:10px">Сохранить</button>
  </form>
  <form method="POST" style="margin-top:8px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate_secret">
    <button class="btn btn-outline btn-sm" type="submit">Сгенерировать новый секрет</button>
  </form>
</div>

<div class="form-card form-wide" style="margin-top:20px">
  <h3 style="margin-top:0">2. Настройка вебхука на GitHub</h3>
  <p style="font-size:13px;color:var(--text-dim)">Репозиторий → Settings → Webhooks → Add webhook:</p>
  <ul style="font-size:13px;color:var(--text-dim);line-height:1.8">
    <li>Payload URL: <code><?= e($webhookUrl) ?></code></li>
    <li>Content type: <code>application/json</code></li>
    <li>Secret: тот же, что задан выше</li>
    <li>Events: только <code>Just the push event</code></li>
  </ul>
  <?php if (!$secret): ?><p style="color:var(--danger)">Сначала сгенерируй секрет выше — без него вебхук будет отклонять все запросы.</p><?php endif; ?>
</div>

<div class="form-card form-wide" style="margin-top:20px">
  <h3 style="margin-top:0">3. Проверить прямо сейчас</h3>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="deploy_now">
    <button class="btn btn-primary" type="submit" <?= (!$owner || !$repo) ? 'disabled' : '' ?>>Задеплоить сейчас (вручную)</button>
  </form>
</div>

<h3 style="margin-top:30px">История деплоев</h3>
<?php if (!$log): ?>
  <p style="color:var(--text-dim);font-size:13px">Пока не было ни одного деплоя.</p>
<?php else: ?>
<div style="overflow-x:auto"><table class="admin-table" style="width:100%">
  <thead><tr><th>Когда</th><th>Коммит</th><th>Статус</th><th>Файлов</th><th>Время</th></tr></thead>
  <tbody>
    <?php foreach ($log as $l): ?>
    <tr>
      <td style="font-size:12px;color:var(--text-dim)"><?= e($l['created_at']) ?></td>
      <td style="font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e((string)$l['commit_message']) ?>">
        <?= $l['commit_sha'] ? e(substr($l['commit_sha'], 0, 7)) . ' — ' : '' ?><?= e((string)$l['commit_message']) ?>
      </td>
      <td><span class="status-pill status-<?= $l['status'] === 'success' ? 'approved' : 'rejected' ?>"><?= e($l['status']) ?></span>
        <?php if ($l['error_message']): ?><div style="font-size:11px;color:var(--danger);max-width:260px"><?= e($l['error_message']) ?></div><?php endif; ?>
      </td>
      <td><?= $l['files_updated'] !== null ? (int)$l['files_updated'] : '—' ?></td>
      <td><?= $l['duration_ms'] !== null ? (int)$l['duration_ms'] . ' мс' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
