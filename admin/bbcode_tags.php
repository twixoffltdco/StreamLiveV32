<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bbcode.php';
$__user = require_admin();
bbcode_ensure_custom_tags_table();

// Имена, которые нельзя переопределить пользовательским тегом — совпадают со встроенными
// (см. $BBCODE_SIMPLE_TAGS и bbcode_callback_tags() в includes/bbcode.php), плюс table/tr/td/th
// и code, у которых своя отдельная обработка выше по цепочке в bbcode_to_html().
const BBCODE_RESERVED_NAMES = [
  'b','i','u','s','center','left','right','justify','sup','sub','indent','spoiler','hide',
  'icode','kbd','mark','h1','h2','h3','h4','plain','youtube','font','email','list',
  'table','tr','td','th','code','hr','url','img','quote','color','size','user','php',
  'html','align','media','attach',
];

// ВАЖНО: вся обработка POST (и любой redirect()) должна происходить ДО require_once
// _layout_start.php ниже — тот файл сразу выводит HTML шапки сайта, а header('Location')
// внутри redirect() физически не срабатывает, если хоть байт ответа уже отправлен браузеру.
// Раньше этот файл подключал _layout_start.php первой строкой, из-за чего после успешного
// добавления/удаления тега страница обрывалась на середине разметки без всякого сообщения
// (redirect() тихо проваливался, затем exit; обрывал вывод) — сама операция при этом всё
// равно применялась к БД, просто админ не видел никакого подтверждения. Поймал живым тестом.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'add') {
    $tagName = strtolower(trim((string)($_POST['tag_name'] ?? '')));
    $replacement = (string)($_POST['replacement'] ?? '');
    $example = trim((string)($_POST['example'] ?? ''));

    if (!preg_match('/^[a-z][a-z0-9_]{1,20}$/', $tagName)) {
      flash_set('error', 'Имя тега: только латиница/цифры/подчёркивание, начинается с буквы, 2-21 символ.');
    } elseif (in_array($tagName, BBCODE_RESERVED_NAMES, true)) {
      flash_set('error', "Тег [{$tagName}] уже встроен в движок — выбери другое имя.");
    } elseif (strpos($replacement, '$1') === false) {
      flash_set('error', 'В шаблоне обязательно должен быть $1 — это место, куда подставится содержимое тега.');
    } else {
      try {
        db()->prepare('INSERT INTO bbcode_custom_tags (tag_name, replacement, example, created_by) VALUES (?, ?, ?, ?)')
          ->execute([$tagName, $replacement, $example ?: null, $__user['id']]);
        flash_set('success', "Тег [{$tagName}] добавлен и сразу доступен везде, где рендерится BBCode.");
      } catch (\Throwable $e) {
        flash_set('error', 'Не удалось сохранить — возможно, тег с таким именем уже существует.');
      }
    }
    redirect('/admin/bbcode_tags.php');
  }

  if ($action === 'toggle') {
    db()->prepare('UPDATE bbcode_custom_tags SET is_active = 1 - is_active WHERE id = ?')->execute([(int)$_POST['id']]);
    redirect('/admin/bbcode_tags.php');
  }

  if ($action === 'delete') {
    db()->prepare('DELETE FROM bbcode_custom_tags WHERE id = ?')->execute([(int)$_POST['id']]);
    flash_set('success', 'Тег удалён');
    redirect('/admin/bbcode_tags.php');
  }
}

require_once __DIR__ . '/_layout_start.php';

$tags = [];
try { $tags = db()->query('SELECT * FROM bbcode_custom_tags ORDER BY id DESC')->fetchAll(); } catch (\Throwable $e) { }
?>
<h2>Свои BBCode-теги</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:640px">
  Добавляй новые парные теги вида <code>[тег]текст[/тег]</code> без правки кода — как
  Custom BB Codes в XenForo. В шаблоне обязательно используй <code>$1</code> — туда
  подставится содержимое между открывающим и закрывающим тегом. Для более сложных тегов
  (с параметрами, самозакрывающихся) по-прежнему нужен код — см. комментарии в верху
  <code>includes/bbcode.php</code>.
</p>

<div class="form-card form-wide">
  <h3 style="margin-top:0">Добавить тег</h3>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label>Имя тега (без скобок, латиница)</label>
    <input type="text" name="tag_name" placeholder="warn" pattern="[a-z][a-z0-9_]{1,20}" required>
    <label>HTML-шаблон (обязательно с $1)</label>
    <textarea name="replacement" rows="3" placeholder='&lt;div class="my-warn-box"&gt;⚠️ $1&lt;/div&gt;' required></textarea>
    <label>Пример использования (необязательно, для памятки)</label>
    <input type="text" name="example" placeholder="[warn]Осторожно, спойлеры![/warn]">
    <button class="btn btn-primary" type="submit" style="margin-top:10px">Добавить тег</button>
  </form>
</div>

<h3 style="margin-top:24px">Уже добавленные</h3>
<?php if (!$tags): ?>
  <p style="color:var(--text-dim);font-size:13px">Пока нет ни одного своего тега.</p>
<?php else: ?>
<div style="overflow-x:auto"><table class="admin-table" style="width:100%">
  <thead><tr><th>Тег</th><th>Шаблон</th><th>Пример</th><th>Статус</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($tags as $t): ?>
    <tr>
      <td><code>[<?= e($t['tag_name']) ?>]</code></td>
      <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="<?= e($t['replacement']) ?>"><?= e($t['replacement']) ?></td>
      <td style="font-size:12px;color:var(--text-dim)"><?= e((string)$t['example']) ?></td>
      <td><span class="status-pill status-<?= $t['is_active'] ? 'approved' : 'pending' ?>"><?= $t['is_active'] ? 'активен' : 'выключен' ?></span></td>
      <td style="display:flex;gap:6px">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-outline btn-sm" type="submit"><?= $t['is_active'] ? 'Выключить' : 'Включить' ?></button></form>
        <form method="POST" onsubmit="return confirm('Удалить тег [<?= e($t['tag_name']) ?>]? Уже написанные посты с этим тегом перестанут форматироваться.')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-danger btn-sm" type="submit">Удалить</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
