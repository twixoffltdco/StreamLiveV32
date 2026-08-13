<?php
/**
 * Системные префиксы — как в модераторской: только is_system, без названий каналов.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_display.php';
require_admin();
user_display_ensure_schema();

try { db()->exec('ALTER TABLE user_prefixes ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
try { db()->exec('ALTER TABLE user_prefixes ADD COLUMN is_personal TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}

// Названия каналов не считаем системными префиксами (только is_system=0, is_active НЕ трогаем)
try {
  $ch = [];
  foreach (db()->query('SELECT title FROM channels')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ct) {
    $k = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$ct)) : strtolower(trim((string)$ct));
    if ($k !== '') $ch[$k] = true;
  }
  foreach (db()->query('SELECT id, title FROM user_prefixes')->fetchAll() ?: [] as $r) {
    $tk = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($r['title'] ?? ''))) : strtolower(trim((string)($r['title'] ?? '')));
    if ($tk !== '' && !empty($ch[$tk])) {
      db()->prepare('UPDATE user_prefixes SET is_system=0 WHERE id=?')->execute([(int)$r['id']]);
    }
  }
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $css = trim((string)($_POST['css'] ?? ''));
    $tc = trim((string)($_POST['text_color'] ?? '#ffffff'));
    $bg = trim((string)($_POST['bg_color'] ?? '#6366f1'));
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = !empty($_POST['is_active']) ? 1 : 0;
    $bad = false;
    if ($title === '') {
      flash_set('error', 'Укажите название');
      $bad = true;
    } else {
      try {
        $st = db()->prepare('SELECT id FROM channels WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) LIMIT 1');
        $st->execute([$title]);
        if ($st->fetch()) {
          flash_set('error', 'Нельзя: такое название уже у канала');
          $bad = true;
        }
      } catch (Throwable $e) {}
    }
    if (!$bad) {
      try {
        if ($id > 0) {
          db()->prepare(
            'UPDATE user_prefixes SET title=?,css=?,text_color=?,bg_color=?,sort_order=?,is_active=?,is_system=1,is_personal=0,owner_user_id=NULL
             WHERE id=? AND COALESCE(is_system,0)=1'
          )->execute([$title, $css, $tc, $bg, $sort, $active, $id]);
        } else {
          db()->prepare(
            'INSERT INTO user_prefixes (title,css,text_color,bg_color,sort_order,is_active,is_personal,owner_user_id,is_system)
             VALUES (?,?,?,?,?,?,0,NULL,1)'
          )->execute([$title, $css, $tc, $bg, $sort, $active]);
        }
        flash_set('success', 'Сохранено');
      } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
      }
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      try { db()->prepare('DELETE FROM user_prefix_map WHERE prefix_id=?')->execute([$id]); } catch (Throwable $e) {}
      try { db()->prepare('UPDATE users SET prefix_id=NULL WHERE prefix_id=?')->execute([$id]); } catch (Throwable $e) {}
      db()->prepare('DELETE FROM user_prefixes WHERE id=? AND COALESCE(is_system,0)=1')->execute([$id]);
      flash_set('success', 'Удалено');
    }
  } elseif ($action === 'assign') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $pids = [];
    if (!empty($_POST['prefix_ids']) && is_array($_POST['prefix_ids'])) {
      foreach ($_POST['prefix_ids'] as $p) {
        $p = (int)$p;
        if ($p > 0) $pids[] = $p;
      }
    }
    $pids = array_slice(array_values(array_unique($pids)), 0, 3);
    $clean = [];
    foreach ($pids as $pid) {
      $st = db()->prepare('SELECT id FROM user_prefixes WHERE id=? AND COALESCE(is_system,0)=1 AND COALESCE(is_personal,0)=0');
      $st->execute([$pid]);
      if ($st->fetch()) $clean[] = $pid;
    }
    if ($uid > 0) {
      if (function_exists('user_set_prefixes')) user_set_prefixes($uid, $clean);
      flash_set('success', 'Назначено');
    }
  }
  redirect('/admin/prefixes');
}

$rows = function_exists('user_prefixes_system_list')
  ? user_prefixes_system_list(false)
  : [];

$users = [];
try {
  $users = db()->query('SELECT id, username FROM users ORDER BY id DESC LIMIT 300')->fetchAll() ?: [];
} catch (Throwable $e) {}
$userPfxMap = [];
try {
  foreach (db()->query('SELECT user_id, prefix_id, sort_order FROM user_prefix_map ORDER BY user_id, sort_order')->fetchAll() ?: [] as $m) {
    $uid = (int)$m['user_id'];
    if (!isset($userPfxMap[$uid])) $userPfxMap[$uid] = [];
    if (count($userPfxMap[$uid]) < 3) $userPfxMap[$uid][] = (int)$m['prefix_id'];
  }
} catch (Throwable $e) {}

$pageTitle = 'Префиксы';
require __DIR__ . '/../includes/header.php';
$flash = flash_get();
?>
<div class="container" style="max-width:900px;padding:24px 0">
  <h1>Системные префиксы</h1>
  <p style="color:var(--text-dim);font-size:13px">Как в модераторской: только системные. Каналы не показываются.</p>
  <?php foreach ($flash as $t => $m): ?>
    <div class="alert alert-<?= e((string)$t) ?>"><?= e((string)$m) ?></div>
  <?php endforeach; ?>

  <div class="form-card" style="margin-bottom:20px">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="pfx-id" value="0">
      <label>Название<input name="title" id="pfx-title" required maxlength="80"></label>
      <label>Цвет текста<input type="color" name="text_color" id="pfx-tc" value="#ffffff"></label>
      <label>Цвет фона<input type="color" name="bg_color" id="pfx-bg" value="#6366f1"></label>
      <label>CSS<textarea name="css" id="pfx-css" rows="2"></textarea></label>
      <label>Сортировка<input type="number" name="sort_order" id="pfx-sort" value="0"></label>
      <label><input type="checkbox" name="is_active" id="pfx-active" value="1" checked> Активен</label>
      <button class="btn btn-primary" type="submit">Сохранить</button>
    </form>
  </div>

  <table class="admin-table" style="width:100%">
    <tr><th>ID</th><th>Превью</th><th>Сорт</th><th>Активен</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><span style="display:inline-block;padding:2px 8px;border-radius:6px;background:<?= e($r['bg_color'] ?? '#6366f1') ?>;color:<?= e($r['text_color'] ?? '#fff') ?>"><?= e($r['title']) ?></span></td>
      <td><?= (int)($r['sort_order'] ?? 0) ?></td>
      <td><?= !empty($r['is_active']) ? 'да' : 'нет' ?></td>
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
    <?php if (!$rows): ?><tr><td colspan="5" style="color:var(--text-dim)">Нет системных — создайте выше</td></tr><?php endif; ?>
  </table>

  <h3 style="margin-top:28px">Выдать (до 3)</h3>
  <form method="post" class="form-card" style="max-width:520px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="assign">
    <label>Пользователь
      <select name="user_id" required onchange="fillUserPfx(this.value)">
        <option value="">— выберите —</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>">@<?= e($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php for ($i = 1; $i <= 3; $i++): ?>
    <label>Префикс <?= $i ?>
      <select name="prefix_ids[]" class="assign-pfx-slot">
        <option value="0">— пусто —</option>
        <?php foreach ($rows as $r): if (empty($r['is_active'])) continue; ?>
          <option value="<?= (int)$r['id'] ?>"><?= e($r['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endfor; ?>
    <button class="btn btn-primary" type="submit">Назначить</button>
  </form>
</div>
<script>
var userPfxMap = <?= json_encode($userPfxMap, JSON_UNESCAPED_UNICODE) ?>;
function fillUserPfx(uid) {
  var slots = document.querySelectorAll('.assign-pfx-slot');
  var list = userPfxMap[uid] || userPfxMap[String(uid)] || [];
  slots.forEach(function (sel, i) { sel.value = list[i] ? String(list[i]) : '0'; });
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
