<?php
require_once __DIR__ . '/_layout_start.php';
require_once dirname(__DIR__) . '/includes/mod_apply.php';
mod_apply_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) {
    try { csrf_verify(); } catch (Throwable $e) {}
  }
  $id = (int)($_POST['id'] ?? 0);
  $action = (string)($_POST['action'] ?? '');
  $note = trim(mb_substr((string)($_POST['admin_note'] ?? ''), 0, 500));
  $adminId = (int)(current_user()['id'] ?? 0);
  try {
    $st = db()->prepare('SELECT * FROM moderator_applications WHERE id=?');
    $st->execute([$id]);
    $app = $st->fetch();
    if ($app && $app['status'] === 'pending') {
      if ($action === 'approve') {
        db()->prepare("UPDATE moderator_applications SET status='approved', reviewed_by=?, reviewed_at=NOW(), admin_note=? WHERE id=?")
          ->execute([$adminId, $note, $id]);
        try {
          db()->prepare("UPDATE users SET role='moderator', role_changed_at=NOW() WHERE id=? AND role NOT IN ('admin')")
            ->execute([(int)$app['user_id']]);
        } catch (Throwable $e) {
          db()->prepare("UPDATE users SET role='moderator' WHERE id=?")->execute([(int)$app['user_id']]);
        }
        flash_set('success', 'Заявка одобрена, роль moderator выдана');
      } elseif ($action === 'reject') {
        db()->prepare("UPDATE moderator_applications SET status='rejected', reviewed_by=?, reviewed_at=NOW(), admin_note=? WHERE id=?")
          ->execute([$adminId, $note, $id]);
        flash_set('success', 'Заявка отклонена');
      }
    }
  } catch (Throwable $e) {
    flash_set('error', $e->getMessage());
  }
  redirect('/admin/mod_applications.php');
}

$rows = [];
try {
  $rows = db()->query(
    "SELECT a.*, u.username FROM moderator_applications a
     JOIN users u ON u.id = a.user_id
     ORDER BY FIELD(a.status,'pending','approved','rejected'), a.id DESC LIMIT 100"
  )->fetchAll() ?: [];
} catch (Throwable $e) {}
?>
<h2>Заявки в модераторы</h2>
<p><a href="/apply_moderator.php" target="_blank">Форма для пользователей</a></p>
<table class="admin-table" style="width:100%;font-size:13px">
  <tr><th>ID</th><th>Кто</th><th>Причина</th><th>Статус</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><a href="/profile?username=<?= e(urlencode($r['username'])) ?>"><?= e($r['username']) ?></a></td>
    <td style="max-width:320px;word-break:break-word">
      <?= e(mb_substr((string)$r['reason'], 0, 200)) ?>
      <?php if (!empty($r['experience'])): ?><br><span style="opacity:.7"><?= e(mb_substr((string)$r['experience'], 0, 120)) ?></span><?php endif; ?>
      <?php if (!empty($r['contacts'])): ?><br><span style="opacity:.7"><?= e($r['contacts']) ?></span><?php endif; ?>
    </td>
    <td><?= e($r['status']) ?></td>
    <td>
      <?php if ($r['status'] === 'pending'): ?>
      <form method="post" style="display:inline"><?= function_exists('csrf_field')?csrf_field():'' ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button name="action" value="approve" class="btn btn-primary btn-sm">Одобрить</button>
        <button name="action" value="reject" class="btn btn-outline btn-sm">Отклонить</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
