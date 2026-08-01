<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/moderator_auth.php';
require_moderator();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)($_POST['id'] ?? 0);
  if (($_POST['action'] ?? '') === 'suspend') {
    $reason = trim((string)($_POST['reason'] ?? '')) ?: 'Приостановлено модератором';
    db()->prepare("UPDATE deployed_services SET suspended = 1, suspended_reason = ?, suspended_by = 'moderator' WHERE id = ?")->execute([$reason, $id]);
    flash_set('success', 'Сервис приостановлен');
  } elseif (($_POST['action'] ?? '') === 'resume') {
    db()->prepare("UPDATE deployed_services SET suspended = 0, suspended_reason = NULL, suspended_by = NULL WHERE id = ?")->execute([$id]);
    flash_set('success', 'Сервис возобновлён');
  }
  redirect('/moderator/services.php');
}

$services = db()->query(
  "SELECT s.*, u.username, u.is_verified FROM deployed_services s JOIN users u ON u.id = s.user_id
   WHERE s.status = 'live' ORDER BY s.suspended DESC, s.created_at DESC"
)->fetchAll();
?>
<div class="container">
  <h1>🧩 Задеплоенные сервисы</h1>
  <?php if (!$services): ?><p style="color:var(--text-dim)">Пока нет ни одного</p><?php endif; ?>
  <?php foreach ($services as $s): ?>
    <div class="card" style="padding:14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div>
        <b><?= e($s['name']) ?></b>
        <?= $s['is_verified'] ? ' <span style="font-size:11px;color:var(--ok)">✓ доверенный (безлимит, без автостопа)</span>' : '' ?>
        <?= $s['suspended'] ? ' <span style="color:var(--danger);font-size:12px">приостановлен</span>' : '' ?>
        <div style="color:var(--text-dim);font-size:12px">
          @<?= e($s['username']) ?> · <a href="/s.php?slug=<?= e($s['slug']) ?>" style="color:var(--accent-2)">/s.php?slug=<?= e($s['slug']) ?></a>
          <?= $s['suspended'] ? '<br>причина: ' . e($s['suspended_reason']) : '' ?>
        </div>
      </div>
      <?php if ($s['suspended']): ?>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="resume">
          <button class="btn btn-ok btn-sm" type="submit">Возобновить</button>
        </form>
      <?php else: ?>
        <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="suspend">
          <input type="text" name="reason" placeholder="Причина (продлите подписку и т.п.)" style="width:200px">
          <button class="btn btn-danger btn-sm" type="submit">Приостановить</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
