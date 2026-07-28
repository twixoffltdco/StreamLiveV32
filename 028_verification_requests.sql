<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/moderator_auth.php';
require_moderator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)($_POST['id'] ?? 0);
  $stmt = db()->prepare('SELECT * FROM verification_requests WHERE id = ?');
  $stmt->execute([$id]);
  $req = $stmt->fetch();

  if ($req) {
    if (($_POST['action'] ?? '') === 'approve') {
      db()->prepare('UPDATE users SET is_verified = 1 WHERE id = ?')->execute([$req['user_id']]);
      db()->prepare("UPDATE verification_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
        ->execute([$__user['id'], $id]);
      flash_set('success', 'Одобрено, галочка выдана');
    } elseif (($_POST['action'] ?? '') === 'reject') {
      $note = trim((string)($_POST['note'] ?? '')) ?: 'без указания причины';
      db()->prepare("UPDATE verification_requests SET status='rejected', reviewed_by=?, review_note=?, reviewed_at=NOW() WHERE id=?")
        ->execute([$__user['id'], $note, $id]);
      flash_set('success', 'Отклонено');
    }
  }
  redirect('/moderator/verification_requests.php');
}

$requests = db()->query(
  "SELECT vr.*, u.username FROM verification_requests vr JOIN users u ON u.id = vr.user_id
   WHERE vr.status = 'pending' ORDER BY vr.created_at ASC"
)->fetchAll();
?>
<div class="container">
  <h1>Заявки на галочку «доверенный» (<?= count($requests) ?>)</h1>
  <?php if (!$requests): ?><p style="color:var(--text-dim)">Пусто</p><?php endif; ?>
  <?php foreach ($requests as $r): ?>
    <div class="card" style="padding:16px;margin-bottom:12px">
      <b>@<?= e($r['username']) ?></b> · представился как: <?= e($r['real_name']) ?>
      <p style="white-space:pre-line;color:var(--text-dim);font-size:13px"><?= e($r['reason']) ?></p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="approve">
          <button class="btn btn-ok btn-sm" type="submit">Одобрить</button>
        </form>
        <form method="POST" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="reject">
          <input type="text" name="note" placeholder="Причина отказа" style="width:180px">
          <button class="btn btn-danger btn-sm" type="submit">Отклонить</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
