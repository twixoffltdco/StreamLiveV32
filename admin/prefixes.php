<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (is_file(__DIR__ . '/../includes/user_display.php')) {
  try { require_once __DIR__ . '/../includes/user_display.php'; } catch (Throwable $e) {}
}
require_admin();
try { if (function_exists('user_display_ensure_schema')) user_display_ensure_schema(); } catch (Throwable $e) {}
try { db()->exec('ALTER TABLE user_prefixes ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
try { db()->exec('ALTER TABLE user_prefixes ADD COLUMN is_personal TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (function_exists('csrf_verify')) csrf_verify();
  } catch (Throwable $e) {}
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'create') {
      $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 80));
      $color = trim((string)($_POST['color'] ?? '#a78bfa'));
      if ($title === '') throw new RuntimeException('Укажите название');
      db()->prepare('INSERT INTO user_prefixes (title, color, is_system, is_active) VALUES (?,?,1,1)')
        ->execute([$title, $color]);
      $ok = 'Префикс создан';
    } elseif ($action === 'delete') {
      db()->prepare('DELETE FROM user_prefixes WHERE id=? AND is_system=1')->execute([(int)($_POST['id'] ?? 0)]);
      $ok = 'Удалено';
    } elseif ($action === 'toggle') {
      db()->prepare('UPDATE user_prefixes SET is_active = IF(is_active=1,0,1) WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
      $ok = 'Обновлено';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$rows = [];
try {
  $rows = db()->query('SELECT * FROM user_prefixes ORDER BY id DESC LIMIT 200')->fetchAll() ?: [];
} catch (Throwable $e) {
  $err = 'Таблица префиксов: ' . $e->getMessage();
}

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Системные префиксы</h2>
<?php if ($err): ?><p style="color:#f87171"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($ok): ?><p style="color:#4ade80"><?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

<form method="post" class="form-card" style="margin:12px 0;padding:12px">
  <?= function_exists('csrf_field') ? csrf_field() : '' ?>
  <input type="hidden" name="action" value="create">
  <label>Название</label>
  <input name="title" required maxlength="80">
  <label>Цвет</label>
  <input name="color" value="#a78bfa">
  <button type="submit" class="btn btn-primary">Создать</button>
</form>

<table class="admin-table" style="width:100%">
  <tr><th>ID</th><th>Название</th><th>system</th><th>active</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><?= htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= (int)($r['is_system'] ?? 0) ?></td>
    <td><?= (int)($r['is_active'] ?? 1) ?></td>
    <td>
      <form method="post" style="display:inline"><?= function_exists('csrf_field')?csrf_field():'' ?>
        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-outline btn-sm">on/off</button>
      </form>
      <?php if (!empty($r['is_system'])): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Удалить?')"><?= function_exists('csrf_field')?csrf_field():'' ?>
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-outline btn-sm">✕</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
