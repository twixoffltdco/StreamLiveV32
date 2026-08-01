<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_display.php';
require_admin();
user_display_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';
  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $css = trim($_POST['css'] ?? '');
    $tc = trim($_POST['text_color'] ?? '#ffffff');
    $bg = trim($_POST['bg_color'] ?? '#6366f1');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = !empty($_POST['is_active']) ? 1 : 0;
    if ($title !== '') {
      if ($id > 0) {
        db()->prepare('UPDATE user_prefixes SET title=?, css=?, text_color=?, bg_color=?, sort_order=?, is_active=? WHERE id=?')
          ->execute([$title, $css, $tc, $bg, $sort, $active, $id]);
      } else {
        db()->prepare('INSERT INTO user_prefixes (title, css, text_color, bg_color, sort_order, is_active) VALUES (?,?,?,?,?,?)')
          ->execute([$title, $css, $tc, $bg, $sort, $active]);
      }
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
      db()->prepare('UPDATE users SET prefix_id = NULL WHERE prefix_id = ?')->execute([$id]);
      db()->prepare('DELETE FROM user_prefixes WHERE id = ?')->execute([$id]);
    }
  } elseif ($action === 'assign') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $pid = (int)($_POST['prefix_id'] ?? 0);
    if ($uid) {
      db()->prepare('UPDATE users SET prefix_id = ? WHERE id = ?')->execute([$pid > 0 ? $pid : null, $uid]);
    }
  }
  redirect('/admin/prefixes.php');
}

$rows = db()->query('SELECT * FROM user_prefixes ORDER BY sort_order ASC, id ASC')->fetchAll();
$users = db()->query('SELECT id, username, prefix_id FROM users ORDER BY id DESC LIMIT 200')->fetchAll();

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Префиксы пользователей (как XenForo)</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:640px">
  Создайте префикс (цвет или свой CSS) и назначьте пользователю. Показывается в профиле, на форуме и в ответах.
</p>

<div class="form-card form-wide" style="margin-bottom:24px">
  <h3>Новый / редактировать</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" id="pfx-id" value="0">
    <label>Название<input name="title" id="pfx-title" required placeholder="VIP / Модератор / Partner"></label>
    <label>Цвет текста<input name="text_color" id="pfx-tc" value="#ffffff" type="color"></label>
    <label>Цвет фона<input name="bg_color" id="pfx-bg" value="#6366f1" type="color"></label>
    <label>Свой CSS (опционально, вместо цветов)<textarea name="css" id="pfx-css" rows="2" placeholder="background:#f59e0b;color:#000;border-radius:4px;padding:2px 6px"></textarea></label>
    <label>Сортировка<input type="number" name="sort_order" id="pfx-sort" value="0"></label>
    <label><input type="checkbox" name="is_active" value="1" id="pfx-active" checked> Активен</label>
    <button class="btn btn-primary" type="submit">Сохранить</button>
  </form>
</div>

<table class="table" style="width:100%;font-size:13px">
  <tr><th>ID</th><th>Превью</th><th>CSS</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= user_render_prefix_html($r) ?></td>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;color:var(--text-dim)"><?= e(mb_substr((string)$r['css'], 0, 40)) ?></td>
      <td>
        <button type="button" class="btn btn-outline btn-sm" onclick='editPfx(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)'>править</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Удалить?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit">удалить</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
</table>

<h3 style="margin-top:28px">Выдать префикс</h3>
<form method="post" class="form-card" style="max-width:480px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="assign">
  <label>Пользователь
    <select name="user_id" required>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>">@<?= e($u['username']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Префикс
    <select name="prefix_id">
      <option value="0">— без префикса —</option>
      <?php foreach ($rows as $r): if (!$r['is_active']) continue; ?>
        <option value="<?= (int)$r['id'] ?>"><?= e($r['title']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn btn-primary" type="submit">Назначить</button>
</form>
<script>
function editPfx(r) {
  document.getElementById('pfx-id').value = r.id;
  document.getElementById('pfx-title').value = r.title || '';
  document.getElementById('pfx-tc').value = r.text_color || '#ffffff';
  document.getElementById('pfx-bg').value = r.bg_color || '#6366f1';
  document.getElementById('pfx-css').value = r.css || '';
  document.getElementById('pfx-sort').value = r.sort_order || 0;
  document.getElementById('pfx-active').checked = !!parseInt(r.is_active, 10);
  window.scrollTo(0, 0);
}
</script>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
