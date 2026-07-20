<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)$_POST['channel_id'];
  $stmt = db()->prepare('SELECT * FROM channels WHERE id = ?');
  $stmt->execute([$id]);
  $channel = $stmt->fetch();

  if ($channel) {
    if ($_POST['action'] === 'approve') {
      db()->prepare("UPDATE channels SET status='approved', reject_reason=NULL WHERE id=?")->execute([$id]);
      db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message) VALUES (?, ?, "channel_approved", ?)')
        ->execute([$channel['owner_id'], $id, "Канал «{$channel['title']}» прошёл модерацию и опубликован в каталоге"]);
      flash_set('success', 'Канал одобрен');
    } elseif ($_POST['action'] === 'reject') {
      $reason = trim($_POST['reason'] ?? '') ?: 'без указания причины';
      db()->prepare("UPDATE channels SET status='rejected', reject_reason=? WHERE id=?")->execute([$reason, $id]);
      db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message) VALUES (?, ?, "channel_rejected", ?)')
        ->execute([$channel['owner_id'], $id, "Канал «{$channel['title']}» отклонён: {$reason}"]);
      flash_set('success', 'Канал отклонён, автору отправлено уведомление');
    }
  }
  redirect('/moderator/index.php');
}

$channels = db()->query(
  "SELECT c.*, u.username as owner_name FROM channels c JOIN users u ON u.id=c.owner_id WHERE c.status='pending' ORDER BY c.created_at ASC"
)->fetchAll();
?>
<h2>Очередь на модерацию (ТВ и радио)</h2>
<?php if (empty($channels)): ?>
  <div class="empty-state">Очередь пуста ✨</div>
<?php endif; ?>
<?php foreach ($channels as $c): ?>
  <div class="card" style="padding:18px;margin-bottom:14px;display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <?php if ($c['logo_url']): ?><img src="<?= e($c['logo_url']) ?>" style="width:56px;height:56px;border-radius:12px;object-fit:cover"><?php endif; ?>
    <div style="flex:1">
      <b><?= e($c['title']) ?></b> <span style="color:var(--text-dim)">(<?= $c['type']==='radio'?'Радио':'ТВ' ?>) — автор: <?= e($c['owner_name']) ?></span>
      <p style="color:var(--text-dim);font-size:13px;margin:6px 0"><?= e($c['description'] ?: 'Без описания') ?></p>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="action" value="approve">
      <button class="btn btn-ok btn-sm" type="submit">Одобрить</button>
    </form>
    <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="action" value="reject">
      <input type="text" name="reason" placeholder="Причина отказа" style="width:100%;max-width:200px;min-width:140px">
      <button class="btn btn-danger btn-sm" type="submit">Отклонить</button>
    </form>
  </div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
