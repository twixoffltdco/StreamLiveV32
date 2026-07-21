<?php
$pageTitle = 'Мои каналы';
require_once __DIR__ . '/includes/header.php';
require_login();

$stmt = db()->prepare('SELECT * FROM channels WHERE owner_id = ? ORDER BY created_at DESC');
$stmt->execute([$__user['id']]);
$channels = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$__user['id']]);
$notifications = $stmt->fetchAll();

$statusLabels = ['pending' => 'На модерации', 'approved' => 'Одобрен', 'rejected' => 'Отклонён'];
?>
<div class="container">
  <div style="display:flex;align-items:center;margin-top:24px">
    <h2 style="margin:0">Мои каналы</h2>
    <a href="/auth/change_password.php" class="btn btn-outline btn-sm" style="margin-left:auto">Сменить пароль</a>
    <a href="/new_channel.php" class="btn btn-primary btn-sm">+ Новый канал</a>
  </div>

  <?php if (empty($channels)): ?>
    <div class="empty-state">У вас пока нет каналов. Создайте первый!</div>
  <?php else: ?>
  <table class="admin-table" style="margin-top:16px">
    <thead><tr><th>Название</th><th>Тип</th><th>Статус</th><th>Просмотры</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($channels as $c): ?>
        <tr>
          <td><?= e($c['title']) ?></td>
          <td><?= $c['type'] === 'radio' ? 'Радио' : 'ТВ' ?></td>
          <td>
            <span class="status-pill status-<?= e($c['status']) ?>"><?= $statusLabels[$c['status']] ?></span>
            <?php if ($c['status'] === 'rejected' && $c['reject_reason']): ?>
              <div style="color:var(--text-dim);font-size:11px;margin-top:4px"><?= e($c['reject_reason']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= (int)$c['views'] ?></td>
          <td><a class="btn btn-outline btn-sm" href="/channel_manage.php?id=<?= (int)$c['id'] ?>">Управлять</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h3 style="margin-top:36px">Уведомления</h3>
  <?php if (empty($notifications)): ?>
    <p style="color:var(--text-dim);font-size:13px">Пока нет уведомлений.</p>
  <?php else: foreach ($notifications as $n): ?>
    <div class="alert <?= $n['type'] === 'channel_rejected' ? 'alert-error' : 'alert-success' ?>"><?= e($n['message']) ?></div>
  <?php endforeach; endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
