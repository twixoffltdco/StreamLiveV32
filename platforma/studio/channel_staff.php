<?php
$studio_title = 'Команда канала';
$studio_active = 'channel_staff';
$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/channel_staff.php';
$user = current_user();
if (!$user) { header('Location: /login.php'); exit; }
$uid = (int)$user['id'];
channel_staff_ensure();

$channelId = (int)($_GET['channel_id'] ?? $_POST['channel_id'] ?? 0);
$channels = [];
try {
  $st = db()->prepare('SELECT id, title, slug FROM channels WHERE owner_id=? ORDER BY title');
  $st->execute([$uid]);
  $channels = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

if ($channelId <= 0 && $channels) $channelId = (int)$channels[0]['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $channelId > 0) {
  if (function_exists('csrf_verify')) { try { csrf_verify(); } catch (Throwable $e) {} }
  $act = (string)($_POST['action'] ?? '');
  if ($act === 'add') {
    $res = channel_staff_add($channelId, $uid, (string)($_POST['username'] ?? ''), (string)($_POST['role'] ?? 'editor'));
    flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Добавлен' : ($res['error'] ?? 'Ошибка'));
  } elseif ($act === 'remove') {
    $res = channel_staff_remove($channelId, $uid, (int)($_POST['user_id'] ?? 0));
    flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Удалён' : ($res['error'] ?? 'Ошибка'));
  }
  redirect('/platforma/studio/channel_staff.php?channel_id=' . $channelId);
}

$staff = $channelId > 0 ? channel_staff_list($channelId) : [];
$can = $channelId > 0 && channel_staff_can_manage($channelId, $uid);
require __DIR__ . '/_layout.php';
$fg = function_exists('flash_get') ? flash_get() : [];
?>
<h1 class="st-h1">Команда канала</h1>
<p class="st-sub">Admin — полный доступ к каналу. Editor — только новый контент и плейлист.</p>
<?php foreach ($fg as $t=>$m): ?><div class="st-panel"><?= htmlspecialchars(is_array($m)?implode(',',$m):(string)$m, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

<form method="get" class="st-panel">
  <label>Канал</label>
  <select name="channel_id" onchange="this.form.submit()">
    <?php foreach ($channels as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $channelId===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></option>
    <?php endforeach; ?>
  </select>
</form>

<?php if ($can): ?>
<form method="post" class="st-panel">
  <?= function_exists('csrf_field')?csrf_field():'' ?>
  <input type="hidden" name="channel_id" value="<?= $channelId ?>">
  <input type="hidden" name="action" value="add">
  <label>Ник пользователя</label>
  <input name="username" required>
  <label>Роль</label>
  <select name="role"><option value="editor">Editor</option><option value="admin">Admin</option></select>
  <button type="submit" class="btn btn-primary">Добавить</button>
</form>
<?php endif; ?>

<div class="st-panel">
  <table class="st-table">
    <tr><th>Пользователь</th><th>Роль</th><th></th></tr>
    <?php foreach ($staff as $s): ?>
    <tr>
      <td><?= htmlspecialchars($s['username'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars($s['role'], ENT_QUOTES, 'UTF-8') ?></td>
      <td>
        <?php if ($can): ?>
        <form method="post" style="display:inline"><?= function_exists('csrf_field')?csrf_field():'' ?>
          <input type="hidden" name="channel_id" value="<?= $channelId ?>">
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="user_id" value="<?= (int)$s['user_id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm">Убрать</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$staff): ?><tr><td colspan="3" class="muted">Пока никого нет</td></tr><?php endif; ?>
  </table>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>
