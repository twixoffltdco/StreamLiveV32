<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paid_access.php';
if (is_file(__DIR__ . '/../includes/moderator_auth.php')) {
  require_once __DIR__ . '/../includes/moderator_auth.php';
  if (function_exists('require_moderator')) require_moderator();
}
paid_ensure_schema();
$u = current_user();
if (!$u || !in_array(($u['role'] ?? ''), ['admin', 'moderator'], true)) {
  http_response_code(403);
  echo 'Только модераторы';
  exit;
}

$quotaUsed = paid_mod_used_daily_quota((int)$u['id']);
if (($u['role'] ?? '') === 'admin') $quotaUsed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) csrf_verify();
  $r = paid_staff_set_paid($u, (int)($_POST['channel_id'] ?? 0), (int)($_POST['paid'] ?? 1), true);
  flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Канал: платный контент включён, замок для владельца' : $r['error']);
  redirect('/moderator/paid_channels.php');
}

$channels = [];
try {
  $channels = db()->query(
    "SELECT c.id, c.title, c.slug, c.paid_content, c.paid_content_locked, u.username AS owner_name
     FROM channels c LEFT JOIN users u ON u.id = c.owner_id
     WHERE c.status = 'approved' ORDER BY c.title ASC LIMIT 300"
  )->fetchAll() ?: [];
} catch (Throwable $e) {}

$pageTitle = 'Платный контент';
require_once __DIR__ . '/../includes/header.php';
if (is_file(__DIR__ . '/_layout_start.php')) require __DIR__ . '/_layout_start.php';
?>
<div class="container" style="max-width:900px;margin:16px auto">
  <h1>Платный контент каналов</h1>
  <p style="opacity:.75">Модератор может <b>1 раз в 24 часа</b> включить/сменить платный доступ на канале. Владелец потом не снимет флаг.</p>
  <?php if ($quotaUsed): ?>
    <div class="alert alert-error">Лимит на сегодня использован. Попробуйте через 24 часа.</div>
  <?php endif; ?>
  <?php if (function_exists('flash_get')): $f = flash_get(); foreach ($f as $t => $m): ?>
    <div class="alert alert-<?= e($t) ?>"><?= e($m) ?></div>
  <?php endforeach; endif; ?>
  <table class="admin-table" style="width:100%">
    <thead><tr><th>Канал</th><th>Владелец</th><th>Сейчас</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($channels as $c): ?>
      <tr>
        <td><?= e($c['title']) ?></td>
        <td><?= e($c['owner_name'] ?? '') ?></td>
        <td><?= !empty($c['paid_content']) ? 'платный' : 'обычный' ?><?= !empty($c['paid_content_locked']) ? ' 🔒' : '' ?></td>
        <td>
          <?php if (!$quotaUsed): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="paid" value="<?= !empty($c['paid_content']) ? 0 : 1 ?>">
            <button class="btn btn-sm btn-primary" type="submit"><?= !empty($c['paid_content']) ? 'Снять платный' : 'Сделать платным' ?></button>
          </form>
          <?php else: ?>
            <span style="opacity:.5">—</span>
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
