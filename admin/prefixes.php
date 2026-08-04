<?php
/**
 * Админка: ТОЛЬКО системные префиксы (is_system=1).
 * Каналы и личные сюда не попадают.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_display.php';
require_admin();
user_display_ensure_schema();

// колонка is_system
try { db()->exec('ALTER TABLE user_prefixes ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}

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

    // запрет: title совпадает с каналом
    $isChannel = false;
    if ($title !== '') {
      try {
        $st = db()->prepare('SELECT id FROM channels WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) LIMIT 1');
        $st->execute([$title]);
        $isChannel = (bool)$st->fetch();
      } catch (Throwable $e) {}
    }

    if ($title === '') {
      if (function_exists('flash_set')) flash_set('error', 'Укажите название');
    } elseif ($isChannel) {
      if (function_exists('flash_set')) flash_set('error', 'Нельзя: такое название уже у канала');
    } else {
      try {
        if ($id > 0) {
          // править только системные
          db()->prepare(
            'UPDATE user_prefixes SET title=?, css=?, text_color=?, bg_color=?, sort_order=?, is_active=?, is_system=1, is_personal=0, owner_user_id=NULL WHERE id=? AND COALESCE(is_system,0)=1'
          )->execute([$title, $css, $tc, $bg, $sort, $active, $id]);
        } else {
          try {
            db()->prepare(
              'INSERT INTO user_prefixes (title, css, text_color, bg_color, sort_order, is_active, is_personal, owner_user_id, is_system) VALUES (?,?,?,?,?,?,0,NULL,1)'
            )->execute([$title, $css, $tc, $bg, $sort, $active]);
          } catch (Throwable $eIns) {
            db()->prepare(
              'INSERT INTO user_prefixes (title, css, text_color, bg_color, sort_order, is_active) VALUES (?,?,?,?,?,?)'
            )->execute([$title, $css, $tc, $bg, $sort, $active]);
            try {
              db()->prepare('UPDATE user_prefixes SET is_system=1, is_personal=0, owner_user_id=NULL WHERE id=?')
                ->execute([(int)db()->lastInsertId()]);
            } catch (Throwable $e2) {}
          }
        }
        if (function_exists('flash_set')) flash_set('success', 'Системный префикс сохранён');
      } catch (Throwable $e) {
        if (function_exists('flash_set')) flash_set('error', 'Ошибка: ' . $e->getMessage());
      }
    }
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      try { db()->prepare('DELETE FROM user_prefix_map WHERE prefix_id = ?')->execute([$id]); } catch (Throwable $e) {}
      try { db()->prepare('UPDATE users SET prefix_id = NULL WHERE prefix_id = ?')->execute([$id]); } catch (Throwable $e) {}
      // удаляем только системные
      try {
        db()->prepare('DELETE FROM user_prefixes WHERE id = ? AND COALESCE(is_system,0)=1')->execute([$id]);
      } catch (Throwable $e) {
        db()->prepare('DELETE FROM user_prefixes WHERE id = ?')->execute([$id]);
      }
    }
  } elseif ($action === 'assign') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $pids = [];
    if (isset($_POST['prefix_ids']) && is_array($_POST['prefix_ids'])) {
      foreach ($_POST['prefix_ids'] as $p) {
        $p = (int)$p;
        if ($p > 0) $pids[] = $p;
      }
    }
    $pids = array_values(array_unique($pids));
    // оставить только is_system=1
    $clean = [];
    foreach ($pids as $pid) {
      try {
        $st = db()->prepare('SELECT id, title FROM user_prefixes WHERE id = ? AND COALESCE(is_system,0)=1 AND COALESCE(is_personal,0)=0');
        $st->execute([$pid]);
        $pr = $st->fetch();
        if (!$pr) continue;
        $clean[] = $pid;
      } catch (Throwable $e) {}
    }
    if ($uid > 0) {
      user_set_prefixes($uid, array_slice($clean, 0, 3));
      if (function_exists('flash_set')) flash_set('success', 'Назначено');
    }
  } elseif ($action === 'mark_existing_system') {
    // Разово: пометить is_system=1 все, что НЕ канал и НЕ personal
    try {
      $all = db()->query('SELECT id, title, is_personal, owner_user_id FROM user_prefixes')->fetchAll() ?: [];
      $ch = [];
      foreach (db()->query('SELECT title FROM channels')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ct) {
        $k = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$ct)) : strtolower(trim((string)$ct));
        if ($k !== '') $ch[$k] = true;
      }
      $n = 0;
      foreach ($all as $r) {
        if (!empty($r['is_personal']) || (int)($r['owner_user_id'] ?? 0) > 0) continue;
        $tk = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$r['title'])) : strtolower(trim((string)$r['title']));
        if ($tk !== '' && !empty($ch[$tk])) continue;
        db()->prepare('UPDATE user_prefixes SET is_system=1 WHERE id=?')->execute([(int)$r['id']]);
        $n++;
      }
      if (function_exists('flash_set')) flash_set('success', 'Помечено системными: ' . $n);
    } catch (Throwable $e) {
      if (function_exists('flash_set')) flash_set('error', $e->getMessage());
    }
  }

  redirect('/admin/prefixes.php');
}

$rows = function_exists('user_prefixes_system_list')
  ? user_prefixes_system_list(false)
  : [];

// Если пусто — показать кнопку «пометить старые»
$users = [];
try {
  $users = db()->query('SELECT id, username FROM users ORDER BY id DESC LIMIT 200')->fetchAll() ?: [];
} catch (Throwable $e) {}

$userPfxMap = [];
try {
  foreach (db()->query('SELECT user_id, prefix_id, sort_order FROM user_prefix_map ORDER BY user_id, sort_order')->fetchAll() ?: [] as $m) {
    $uid = (int)$m['user_id'];
    if (!isset($userPfxMap[$uid])) $userPfxMap[$uid] = [];
    if (count($userPfxMap[$uid]) < 3) $userPfxMap[$uid][] = (int)$m['prefix_id'];
  }
} catch (Throwable $e) {}

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Системные префиксы</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:560px">
  Здесь только префиксы, созданные в админке (VIP, Модератор и т.д.).
  Названия каналов и личные префиксы юзеров <b>не показываются</b>.
</p>

<?php if (empty($rows)): ?>
<div class="alert" style="margin-bottom:16px;background:rgba(245,158,11,.12);padding:12px;border-radius:10px">
  Список пуст. Создай новый префикс ниже.
  Если раньше уже были VIP/Модератор — нажми:
  <form method="post" style="display:inline;margin-left:8px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark_existing_system">
    <button type="submit" class="btn btn-outline btn-sm">Пометить старые системные (не каналы)</button>
  </form>
</div>
<?php endif; ?>

<div class="form-card form-wide" style="margin-bottom:24px">
  <h3>Новый / править</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" id="pfx-id" value="0">
    <label>Название<input name="title" id="pfx-title" required placeholder="VIP / Модератор / Partner"></label>
    <label>Цвет текста<input name="text_color" id="pfx-tc" value="#ffffff" type="color"></label>
    <label>Цвет фона<input name="bg_color" id="pfx-bg" value="#6366f1" type="color"></label>
    <label>Свой CSS<textarea name="css" id="pfx-css" rows="2" placeholder="background:#f59e0b;color:#000;border-radius:4px;padding:2px 6px"></textarea></label>
    <label>Сортировка<input name="sort_order" id="pfx-sort" type="number" value="0"></label>
    <label><input type="checkbox" name="is_active" id="pfx-active" value="1" checked> Активен</label>
    <button class="btn btn-primary" type="submit">Сохранить</button>
  </form>
</div>

<table class="table" style="width:100%;font-size:13px">
  <tr><th>ID</th><th>Превью</th><th>Сорт</th><th>Активен</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><?= function_exists('user_render_prefix_html') ? user_render_prefix_html($r) : e($r['title']) ?></td>
    <td><?= (int)$r['sort_order'] ?></td>
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
  <?php if (!$rows): ?><tr><td colspan="5" style="color:var(--text-dim)">Нет системных префиксов</td></tr><?php endif; ?>
</table>

<h3 style="margin-top:28px">Выдать (до 3)</h3>
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
      <?php foreach ($rows as $r): if (empty($r['is_active'])) continue; ?>
        <option value="<?= (int)$r['id'] ?>"><?= e($r['title']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endfor; ?>
  <button class="btn btn-primary" type="submit">Назначить</button>
</form>
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
<?php require_once __DIR__ . '/_layout_end.php'; ?>
