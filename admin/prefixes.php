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
        db()->prepare('INSERT INTO user_prefixes (title, css, text_color, bg_color, sort_order, is_active, is_personal, owner_user_id) VALUES (?,?,?,?,?,?,0,NULL)')
          ->execute([$title, $css, $tc, $bg, $sort, $active]);
      }
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
      try {
        db()->prepare('DELETE FROM user_prefix_map WHERE prefix_id = ?')->execute([$id]);
      } catch (Throwable $e) {}
      db()->prepare('UPDATE users SET prefix_id = NULL WHERE prefix_id = ?')->execute([$id]);
      db()->prepare('DELETE FROM user_prefixes WHERE id = ?')->execute([$id]);
    }
  } elseif ($action === 'assign') {
    $uid = (int)($_POST['user_id'] ?? 0);
    // до 3 префиксов
    $pids = [];
    if (isset($_POST['prefix_ids']) && is_array($_POST['prefix_ids'])) {
      foreach ($_POST['prefix_ids'] as $p) {
        $p = (int)$p;
        if ($p > 0) $pids[] = $p;
      }
    } else {
      // backward compat: один select
      $one = (int)($_POST['prefix_id'] ?? 0);
      if ($one > 0) $pids[] = $one;
    }
    if ($uid) {
      user_set_prefixes($uid, $pids);
    }
  }
  redirect('/admin/prefixes.php');
}

$allRows = db()->query('SELECT * FROM user_prefixes ORDER BY sort_order ASC, id ASC')->fetchAll();
$rows = array_values(array_filter($allRows, static function ($r) {
  return empty($r['is_personal']) && empty($r['owner_user_id']);
}));
$personalRows = array_values(array_filter($allRows, static function ($r) {
  return !empty($r['is_personal']) || !empty($r['owner_user_id']);
}));
$users = db()->query('SELECT id, username, prefix_id FROM users ORDER BY id DESC LIMIT 200')->fetchAll();

// текущие назначения (до 3)
$userPfxMap = [];
try {
  $mapRows = db()->query(
    'SELECT user_id, prefix_id, sort_order FROM user_prefix_map ORDER BY user_id, sort_order ASC'
  )->fetchAll();
  foreach ($mapRows as $m) {
    $uid = (int)$m['user_id'];
    if (!isset($userPfxMap[$uid])) $userPfxMap[$uid] = [];
    if (count($userPfxMap[$uid]) < 3) {
      $userPfxMap[$uid][] = (int)$m['prefix_id'];
    }
  }
} catch (Throwable $e) {}

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Префиксы пользователей (как XenForo)</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:640px">
  Создайте префикс (цвет или свой CSS) и назначьте пользователю <b>до 3 штук</b>.
  Показывается в профиле, мини-профиле, на форуме и в ответах — каждый префикс один раз.
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

<h3 style="margin-top:28px">Выдать префикс (только системные)ы (до 3 на аккаунт)</h3>
<form method="post" class="form-card" style="max-width:520px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="assign">
  <label>Пользователь
    <select name="user_id" id="assign-user" required onchange="fillUserPfx(this.value)">
      <option value="">— выберите —</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>">@<?= e($u['username']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php for ($slot = 1; $slot <= 3; $slot++): ?>
  <label>Префикс <?= $slot ?>
    <select name="prefix_ids[]" class="assign-pfx-slot">
      <option value="0">— пусто —</option>
      <?php foreach ($rows as $r): if (!$r['is_active']) continue; ?>
        <option value="<?= (int)$r['id'] ?>"><?= e($r['title']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endfor; ?>
  <p style="font-size:12px;color:var(--text-dim);margin:4px 0 10px">
    Один и тот же префикс дважды не ставится. Пустые слоты игнорируются.
  </p>
  <button class="btn btn-primary" type="submit">Назначить</button>
</form>
<script>
var userPfxMap = <?= json_encode($userPfxMap, JSON_UNESCAPED_UNICODE) ?>;
function fillUserPfx(uid) {
  var slots = document.querySelectorAll('.assign-pfx-slot');
  var list = userPfxMap[uid] || userPfxMap[String(uid)] || [];
  slots.forEach(function (sel, i) {
    sel.value = list[i] ? String(list[i]) : '0';
  });
}
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
<?php if (!empty($personalRows)): ?>
<h3 style="margin-top:28px">Личные префиксы пользователей (не выдаются)</h3>
<p style="color:var(--text-dim);font-size:13px">Созданы юзерами для себя — только на их аккаунте.</p>
<table class="table" style="width:100%;font-size:13px">
  <tr><th>ID</th><th>Превью</th><th>owner</th></tr>
  <?php foreach ($personalRows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= function_exists('user_render_prefix_html') ? user_render_prefix_html($r) : e($r['title']) ?></td>
      <td><?= (int)($r['owner_user_id'] ?? 0) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>

