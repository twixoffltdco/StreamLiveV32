<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/forum_engine.php';
require_once __DIR__ . '/../includes/forum_engine.php';
$__user = require_admin();
forum_engine_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)($_POST['id'] ?? 0);
  $action = (string)($_POST['action'] ?? '');
  $st = db()->prepare('SELECT * FROM forum_post_reports WHERE id = ?');
  $st->execute([$id]);
  $rep = $st->fetch();
  if ($rep) {
    $postId = (int)$rep['post_id'];
    if ($action === 'close') {
      db()->prepare("UPDATE forum_post_reports SET status='closed', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
        ->execute([(int)$__user['id'], $id]);
      flash_set('success', 'Жалоба закрыта без действий');
    } elseif ($action === 'delete_post') {
      db()->prepare('UPDATE forum_posts SET is_deleted=1 WHERE id=?')->execute([$postId]);
      db()->prepare("UPDATE forum_post_reports SET status='closed', reviewed_by=?, reviewed_at=NOW(), review_note='post deleted' WHERE id=?")
        ->execute([(int)$__user['id'], $id]);
      flash_set('success', 'Пост удалён, жалоба закрыта');
    } elseif ($action === 'lock_thread') {
      $p = db()->prepare('SELECT thread_id FROM forum_posts WHERE id=?');
      $p->execute([$postId]);
      $tid = (int)$p->fetchColumn();
      if ($tid) {
        db()->prepare('UPDATE forum_threads SET is_locked=1 WHERE id=?')->execute([$tid]);
      }
      db()->prepare("UPDATE forum_post_reports SET status='closed', reviewed_by=?, reviewed_at=NOW(), review_note='thread locked' WHERE id=?")
        ->execute([(int)$__user['id'], $id]);
      flash_set('success', 'Тема закрыта');
    }
  }
  redirect('/admin/forum_reports');
}

require_once __DIR__ . '/_layout_start.php';
$rows = [];
try {
  $rows = db()->query(
    "SELECT r.*, u.username AS reporter, p.message, p.thread_id, tu.username AS author
     FROM forum_post_reports r
     JOIN users u ON u.id = r.reporter_id
     JOIN forum_posts p ON p.id = r.post_id
     JOIN users tu ON tu.id = p.user_id
     WHERE r.status = 'open' ORDER BY r.created_at DESC LIMIT 80"
  )->fetchAll();
} catch (Throwable $e) {}
?>
<div class="container">
  <h1>Жалобы на форум (<?= count($rows) ?>)</h1>
  <p style="color:var(--text-dim);font-size:13px">Можно: закрыть жалобу · удалить пост · закрыть тему</p>
  <?php if (!$rows): ?><p style="color:var(--text-dim)">Пусто</p><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <div class="card" style="padding:14px;margin-bottom:10px">
      <div style="font-size:12px;color:var(--text-dim)">@<?= e($r['reporter']) ?> → @<?= e($r['author']) ?> · <?= e($r['reason']) ?> · <?= e($r['created_at']) ?></div>
      <p style="font-size:13px;white-space:pre-wrap"><?= e(mb_substr($r['message'], 0, 400)) ?></p>
      <a href="/forum_thread?id=<?= (int)$r['thread_id'] ?>#post-<?= (int)$r['post_id'] ?>" style="color:var(--accent-2)">В теме</a>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="close">
          <button class="btn btn-outline btn-sm" type="submit">Только закрыть жалобу</button></form>
        <form method="POST" onsubmit="return confirm('Удалить пост?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="delete_post">
          <button class="btn btn-danger btn-sm" type="submit">Удалить пост</button></form>
        <form method="POST" onsubmit="return confirm('Закрыть тему?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="lock_thread">
          <button class="btn btn-outline btn-sm" type="submit">Закрыть тему</button></form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
