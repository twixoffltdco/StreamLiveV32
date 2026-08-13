<?php
require_once __DIR__ . '/_layout_start.php';
/**
 * Передача: тема форума, канал, видео → другому user_id
 * Админ — без лимита. Модератор — через cmod_can_act (200ч).
 */
$u = current_user();
$isAdmin = is_array($u) && (($u['role'] ?? '') === 'admin');
$isMod = is_array($u) && in_array(($u['role'] ?? ''), ['admin','moderator'], true);
if (!$isMod) { echo 'Нет доступа'; require __DIR__.'/_layout_end.php'; exit; }

if (is_file(dirname(__DIR__).'/includes/content_moderation.php')) {
  require_once dirname(__DIR__).'/includes/content_moderation.php';
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) { try { csrf_verify(); } catch (Throwable $e) {} }
  if (!$isAdmin && function_exists('cmod_can_act')) {
    $can = cmod_can_act($u);
    if (!$can['ok']) {
      $msg = $can['error'] ?? 'Лимит';
    }
  }
  if ($msg === '') {
    $type = (string)($_POST['type'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $toUser = trim((string)($_POST['to_username'] ?? ''));
    try {
      $st = db()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
      $st->execute([$toUser]);
      $newUid = (int)$st->fetchColumn();
      if ($newUid <= 0) throw new RuntimeException('Пользователь не найден');
      if ($type === 'thread') {
        db()->prepare('UPDATE forum_threads SET user_id=? WHERE id=?')->execute([$newUid, $id]);
      } elseif ($type === 'channel') {
        db()->prepare('UPDATE channels SET owner_id=? WHERE id=?')->execute([$newUid, $id]);
      } elseif ($type === 'video') {
        db()->prepare('UPDATE videos SET user_id=? WHERE id=?')->execute([$newUid, $id]);
      } else {
        throw new RuntimeException('Тип: thread / channel / video');
      }
      if (!$isAdmin && function_exists('cmod_log_action')) {
        cmod_log_action((int)$u['id'], 'transfer:'.$type.':'.$id);
      }
      $msg = 'Передано';
    } catch (Throwable $e) {
      $msg = $e->getMessage();
    }
  }
}
?>
<h2>Передача собственности</h2>
<p>Админ — без лимита. Модератор — 1 действие / 200 часов.</p>
<?php if ($msg): ?><div class="card" style="padding:12px;margin:10px 0"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" class="form-card">
  <?= function_exists('csrf_field')?csrf_field():'' ?>
  <label>Тип</label>
  <select name="type">
    <option value="thread">Тема форума</option>
    <option value="channel">Канал (ТВ/радио)</option>
    <option value="video">Видео</option>
  </select>
  <label>ID объекта</label>
  <input name="id" type="number" required min="1">
  <label>Новый владелец (username)</label>
  <input name="to_username" required>
  <button type="submit" class="btn btn-primary">Передать</button>
</form>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
