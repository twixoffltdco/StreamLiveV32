<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paid_access.php';
paid_ensure_schema();
$u = current_user();
if (!$u || ($u['role'] ?? '') !== 'admin') {
  http_response_code(403);
  echo 'Только администратор';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) csrf_verify();
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'set') {
    $r = paid_staff_set_paid($u, (int)($_POST['channel_id'] ?? 0), (int)($_POST['paid'] ?? 0), true);
    flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Сохранено (замок включён)' : $r['error']);
  } elseif ($action === 'unlock') {
    $r = paid_staff_set_paid($u, (int)($_POST['channel_id'] ?? 0), (int)($_POST['paid'] ?? 0), false);
    // force unlock
    try {
      db()->prepare('UPDATE channels SET paid_content_locked=0, paid_locked_by=NULL, paid_locked_at=NULL WHERE id=?')
        ->execute([(int)$_POST['channel_id']]);
      flash_set('success', 'Замок снят');
    } catch (Throwable $e) {
      flash_set('error', $e->getMessage());
    }
  } elseif ($action === 'bulk_paid') {
    $r = paid_admin_bulk_set_all($u, 1);
    flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? ('Готово: каналов обновлено ≈ ' . (int)$r['count']) : $r['error']);
  }
  redirect('/admin/paid_channels.php');
}

$channels = [];
try {
  $channels = db()->query(
    "SELECT c.id, c.title, c.slug, c.status, c.paid_content, c.paid_content_locked, c.paid_locked_at, u.username AS owner_name
     FROM channels c LEFT JOIN users u ON u.id = c.owner_id
     WHERE c.status = 'approved'
     ORDER BY c.paid_content DESC, c.title ASC
     LIMIT 500"
  )->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $channels = db()->query(
      "SELECT c.id, c.title, c.slug, c.status, c.paid_content, u.username AS owner_name
       FROM channels c LEFT JOIN users u ON u.id = c.owner_id
       WHERE c.status = 'approved' ORDER BY c.title ASC LIMIT 500"
    )->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}

$pageTitle = 'Платный контент каналов';
require_once __DIR__ . '/../includes/header.php';
if (is_file(__DIR__ . '/_layout_start.php')) require __DIR__ . '/_layout_start.php';
?>
<div class="container" style="max-width:960px;margin:20px auto">
  <h1>Платный / закрытый контент</h1>
  <p style="opacity:.75">Если админ или модер включает платный доступ — владелец <b>не может</b> снять флаг. Модер: 1 канал / 24 ч.</p>
  <?php if (function_exists('flash_get')): $f = flash_get(); foreach ($f as $t => $m): ?>
    <div class="alert alert-<?= e($t) ?>"><?= e($m) ?></div>
  <?php endforeach; endif; ?>

  <form method="post" style="margin:16px 0" onsubmit="return confirm('Включить платный контент на ВСЕХ approved-каналах? Владельцы не снимут флаг.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk_paid">
    <button class="btn btn-primary" type="submit">Сделать все approved-каналы платными (замок)</button>
  </form>

  <table class="admin-table" style="width:100%">
    <thead>
      <tr><th>Канал</th><th>Владелец</th><th>Платный</th><th>Замок</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($channels as $c): ?>
        <tr>
          <td><a href="/channel.php?slug=<?= e($c['slug']) ?>"><?= e($c['title']) ?></a></td>
          <td><?= e($c['owner_name'] ?? '') ?></td>
          <td><?= !empty($c['paid_content']) ? 'да' : 'нет' ?></td>
          <td><?= !empty($c['paid_content_locked']) ? '🔒' : '—' ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set">
              <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="paid" value="<?= !empty($c['paid_content']) ? 0 : 1 ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= !empty($c['paid_content']) ? 'Снять платный' : 'Сделать платным' ?></button>
            </form>
            <?php if (!empty($c['paid_content_locked'])): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="unlock">
              <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="paid" value="<?= !empty($c['paid_content']) ? 1 : 0 ?>">
              <button class="btn btn-outline btn-sm" type="submit">Снять замок</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
if (is_file(__DIR__ . '/_layout_end.php')) require __DIR__ . '/_layout_end.php';
else require_once __DIR__ . '/../includes/footer.php';
