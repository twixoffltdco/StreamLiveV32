<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/moderator_auth.php';
require_once __DIR__ . '/../includes/forum_engine.php';
require_moderator();
forum_engine_ensure();
$__user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)($_POST['id'] ?? 0);
  $action = $_POST['action'] ?? '';
  if ($id > 0 && $action === 'close') {
    try {
      db()->prepare("UPDATE forum_post_reports SET status='closed', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
        ->execute([(int)$__user['id'], $id]);
      flash_set('success', 'Жалоба закрыта');
    } catch (Throwable $e) {
      flash_set('error', $e->getMessage());
    }
  }
  redirect('/moderator/forum_reports.php');
}

$rows = [];
try {
  $rows = db()->query(
    "SELECT r.*, u.username AS reporter, p.message, p.thread_id, tu.username AS author
     FROM forum_post_reports r
     JOIN users u ON u.id = r.reporter_id
     JOIN forum_posts p ON p.id = r.post_id
     JOIN users tu ON tu.id = p.user_id
     WHERE r.status = 'open'
     ORDER BY r.created_at DESC LIMIT 80"
  )->fetchAll();
} catch (Throwable $e) {}
?>
<div class="container">
  <h1>Жалобы на форум (<?= count($rows) ?>)</h1>
  <?php if (!$rows): ?><p style="color:var(--text-dim)">Пусто</p><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <div class="card" style="padding:14px;margin-bottom:10px">
      <div style="font-size:12px;color:var(--text-dim)">@<?= e($r['reporter']) ?> → @<?= e($r['author']) ?> · <?= e($r['reason']) ?></div>
      <p style="font-size:13px;white-space:pre-wrap"><?= e(mb_substr($r['message'], 0, 400)) ?></p>
      <a href="/forum_thread.php?id=<?= (int)$r['thread_id'] ?>#post-<?= (int)$r['post_id'] ?>" style="color:var(--accent-2)">В теме</a>
      <form method="POST" style="display:inline;margin-left:8px"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="action" value="close">
        <button class="btn btn-outline btn-sm" type="submit">Закрыть</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
