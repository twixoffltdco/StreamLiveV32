<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
require_once __DIR__ . '/../includes/service_helpers.php';
deployed_services_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)($_POST['id'] ?? 0);
  $reason = trim((string)($_POST['reason'] ?? ''));
  if (($_POST['action'] ?? '') === 'suspend') {
    if ($reason === '') $reason = 'Услуга окончена. Продлите подписку для возобновления.';
    db()->prepare('UPDATE deployed_services SET suspended = 1, suspended_reason = ? WHERE id = ?')->execute([$reason, $id]);
    flash_set('success', 'Сервис остановлен');
  } elseif (($_POST['action'] ?? '') === 'resume') {
    db()->prepare('UPDATE deployed_services SET suspended = 0, suspended_reason = NULL WHERE id = ?')->execute([$id]);
    flash_set('success', 'Сервис возобновлён');
  }
  redirect('/admin/services.php');
}

$services = db()->query(
  "SELECT s.*, u.username, u.is_verified, u.last_active_date
   FROM deployed_services s JOIN users u ON u.id = s.user_id
   ORDER BY s.suspended DESC, s.created_at DESC LIMIT 300"
)->fetchAll();
?>
<h2>Задеплоенные сервисы</h2>
<p style="color:var(--text-dim);font-size:13px">Без галочки у пользователя доступен 1 активный сервис; с галочкой — безлимит и без автостопа 30 дней.</p>
<table class="admin-table">
  <thead><tr><th>Сервис</th><th>Автор</th><th>Статус</th><th>Превью</th><th>Действия</th></tr></thead>
  <tbody>
    <?php foreach ($services as $s): $s = deployed_service_autostop($s); ?>
      <tr>
        <td><b><?= e($s['name']) ?></b><br><small><?= e($s['repo_full_name']) ?> @ <?= e($s['branch']) ?></small></td>
        <td>@<?= e($s['username']) ?><?= $s['is_verified'] ? ' <span class="verify-badge">✓</span>' : '' ?><br><small>последний вход: <?= e($s['last_active_date'] ?: '—') ?></small></td>
        <td>
          <span class="status-pill status-<?= $s['status'] === 'live' ? 'approved' : ($s['status'] === 'failed' ? 'rejected' : 'pending') ?>"><?= e($s['status']) ?></span>
          <?php if (!empty($s['suspended'])): ?><br><span class="status-pill status-rejected">остановлен</span><br><small><?= e($s['suspended_reason']) ?></small><?php endif; ?>
        </td>
        <td><?php if ($s['status'] === 'live'): ?><a href="/s.php?slug=<?= e($s['slug']) ?>" target="_blank">открыть</a><?php else: ?>—<?php endif; ?></td>
        <td>
          <?php if (!empty($s['suspended'])): ?>
            <form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="resume"><button class="btn btn-ok btn-sm">Возобновить</button></form>
          <?php else: ?>
            <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="suspend"><input name="reason" placeholder="Причина: продлите подписку" style="max-width:220px"><button class="btn btn-danger btn-sm">Остановить</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
