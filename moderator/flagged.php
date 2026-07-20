<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)$_POST['channel_id'];
  $stmt = db()->prepare('SELECT * FROM channels WHERE id = ?');
  $stmt->execute([$id]);
  $channel = $stmt->fetch();

  if ($channel) {
    if ($_POST['action'] === 'hide') {
      $reason = trim($_POST['reason'] ?? '') ?: 'Нарушение правил платформы';
      db()->prepare("UPDATE channels SET status='hidden', hidden_reason=?, hidden_by=?, hidden_at=NOW() WHERE id=?")
        ->execute([$reason, $__user['id'], $id]);
      db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message) VALUES (?, ?, "channel_hidden", ?)')
        ->execute([$channel['owner_id'], $id, "Канал «{$channel['title']}» скрыт модератором: {$reason}"]);
      flash_set('success', 'Канал скрыт из каталога');
    } elseif ($_POST['action'] === 'restore') {
      db()->prepare("UPDATE channels SET status='approved', hidden_reason=NULL, hidden_by=NULL, hidden_at=NULL WHERE id=?")->execute([$id]);
      flash_set('success', 'Канал восстановлен в каталоге');
    }
  }
  redirect('/moderator/flagged.php');
}

$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
  $stmt = db()->prepare(
    "SELECT c.*, u.username as owner_name FROM channels c JOIN users u ON u.id=c.owner_id
     WHERE c.status IN ('approved','hidden') AND c.title LIKE ? ORDER BY c.status='hidden' DESC, c.created_at DESC LIMIT 100"
  );
  $stmt->execute(['%' . $search . '%']);
} else {
  $stmt = db()->query(
    "SELECT c.*, u.username as owner_name FROM channels c JOIN users u ON u.id=c.owner_id
     WHERE c.status IN ('approved','hidden') ORDER BY c.status='hidden' DESC, c.created_at DESC LIMIT 100"
  );
}
$channels = $stmt->fetchAll();
?>
<h2>Скрыть/восстановить канал</h2>
<p style="color:var(--text-dim)">Здесь модерируются уже опубликованные ТВ/радио каналы — если канал одобрили по ошибке или он начал нарушать правила, его можно скрыть из каталога (не удаляя данные) либо вернуть обратно.</p>

<form method="GET" style="margin:12px 0">
  <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск по названию" style="padding:8px;width:100%;max-width:280px">
  <button class="btn btn-outline btn-sm" type="submit">Найти</button>
</form>

<div style="overflow-x:auto"><table class="admin-table" style="width:100%;min-width:560px">
  <thead><tr><th>Название</th><th>Автор</th><th>Статус</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($channels as $c): ?>
    <tr>
      <td><a href="/channel.php?slug=<?= e($c['slug']) ?>" style="color:var(--accent-2)"><?= e($c['title']) ?></a> <span style="color:var(--text-dim)">(<?= $c['type']==='radio'?'Радио':'ТВ' ?>)</span></td>
      <td><?= e($c['owner_name']) ?></td>
      <td>
        <span class="status-pill status-<?= e($c['status']) ?>"><?= e($c['status']) ?></span>
        <?php if ($c['status'] === 'hidden' && $c['hidden_reason']): ?><div style="font-size:12px;color:var(--text-dim)"><?= e($c['hidden_reason']) ?></div><?php endif; ?>
      </td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($c['status'] === 'approved'): ?>
        <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="action" value="hide">
          <input type="text" name="reason" placeholder="Причина (спам/18+/копирайт...)" style="width:100%;max-width:220px;min-width:140px">
          <button class="btn btn-danger btn-sm" type="submit">Скрыть</button>
        </form>
        <?php else: ?>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="action" value="restore">
          <button class="btn btn-ok btn-sm" type="submit">Восстановить</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
