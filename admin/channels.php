<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)$_POST['channel_id'];
  if ($_POST['action'] === 'takedown') {
    $stmt = db()->prepare('SELECT * FROM channels WHERE id = ?');
    $stmt->execute([$id]);
    $channel = $stmt->fetch();
    if ($channel) {
      $reason = trim($_POST['reason'] ?? '') ?: 'Нарушение правил платформы';
      db()->prepare("UPDATE channels SET status='rejected', reject_reason=?, locked_by_admin=1 WHERE id=?")->execute([$reason, $id]);
      db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message) VALUES (?, ?, "channel_rejected", ?)')
        ->execute([$channel['owner_id'], $id, "Канал «{$channel['title']}» снят с публикации: {$reason}"]);
      flash_set('success', 'Канал снят с публикации — решение зафиксировано за администратором, модератор не сможет одобрить его обратно');
    }
  } elseif ($_POST['action'] === 'restore') {
    // Отменить своё же решение может только сам админ — снимает и статус, и блокировку.
    db()->prepare("UPDATE channels SET status='approved', reject_reason=NULL, locked_by_admin=0 WHERE id=?")->execute([$id]);
    flash_set('success', 'Канал восстановлен администратором');
  } elseif ($_POST['action'] === 'delete') {
    db()->prepare('DELETE FROM channels WHERE id = ?')->execute([$id]);
    flash_set('success', 'Канал удалён');
  }
  redirect('/admin/channels.php');
}

$channels = db()->query(
  "SELECT c.*, u.username as owner_name FROM channels c JOIN users u ON u.id=c.owner_id ORDER BY c.created_at DESC"
)->fetchAll();
?>
<h2>Все каналы</h2>
<table class="admin-table">
  <thead><tr><th>Название</th><th>Автор</th><th>Статус</th><th>Просмотры</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($channels as $c): ?>
    <tr>
      <td><a href="/channel.php?slug=<?= e($c['slug']) ?>" style="color:var(--accent-2)"><?= e($c['title']) ?></a></td>
      <td><?= e($c['owner_name']) ?></td>
      <td><span class="status-pill status-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
      <td><?= (int)$c['views'] ?></td>
      <td style="display:flex;gap:6px">
        <?php if ($c['status'] === 'approved'): ?>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="action" value="takedown">
          <input type="hidden" name="reason" value="Нарушение правил платформы">
          <button class="btn btn-danger btn-sm" type="submit">Снять с публикации</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
