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
  redirect('/admin/moderation.php');
}

$channels = db()->query(
  "SELECT c.*, u.username as owner_name FROM channels c JOIN users u ON u.id=c.owner_id WHERE c.status='pending' ORDER BY c.created_at ASC"
)->fetchAll();
?>
<h2>Модерация каналов</h2>
<?php if (empty($channels)): ?>
  <div class="empty-state">Очередь пуста ✨</div>
<?php endif; ?>
<?php foreach ($channels as $c): ?>
  <div class="card mini-preview" style="padding:18px;margin-bottom:14px">
    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
    <?php if ($c['logo_url']): ?><img src="<?= e($c['logo_url']) ?>" style="width:72px;height:72px;border-radius:14px;object-fit:cover;border:1px solid rgba(255,255,255,.1)"><?php else: ?><div style="width:72px;height:72px;border-radius:14px;background:#222;display:flex;align-items:center;justify-content:center;opacity:.5">нет лого</div><?php endif; ?>
    <div style="flex:1;min-width:200px">
      <b style="font-size:16px"><?= e($c['title']) ?></b>
      <div style="color:var(--text-dim);font-size:13px;margin-top:4px"><?= $c['type']==='radio'?'Радио':'ТВ' ?> · автор: <?= e($c['owner_name']) ?> · slug: <code><?= e($c['slug'] ?? '') ?></code></div>
      <p style="color:var(--text-dim);font-size:13px;margin:8px 0"><?= e($c['description'] ?: 'Без описания') ?></p>
      <div style="font-size:12px;opacity:.65">Создан: <?= e((string)($c['created_at'] ?? '')) ?></div>
      <?php if (!empty($c['stream_url']) || !empty($c['embed_url'])): ?>
        <div style="font-size:11px;margin-top:6px;opacity:.5;word-break:break-all">Источник: <?= e(mb_substr((string)($c['stream_url'] ?? $c['embed_url'] ?? ''), 0, 120)) ?></div>
      <?php endif; ?>
      <a href="/channel.php?slug=<?= e(urlencode($c['slug'] ?? '')) ?>" target="_blank" style="font-size:13px">Мини-превью канала →</a>
    </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;align-items:center">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="action" value="approve">
      <button class="btn btn-ok btn-sm" type="submit">Одобрить</button>
    </form>
    <form method="POST" style="display:flex;gap:6px">
      <?= csrf_field() ?>
      <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="action" value="reject">
      <input type="text" name="reason" placeholder="Причина отказа" style="width:180px">
      <button class="btn btn-danger btn-sm" type="submit">Отклонить</button>
    </form>
    </div>
  </div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
