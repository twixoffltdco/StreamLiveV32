<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/data_import.php';
$__user = require_admin();

// Объявлена БЕЗУСЛОВНО, до любых if/elseif — используется ниже прямо в разметке того же
// условного блока, где определяется. Если объявить её внутри if(), PHP зарегистрирует
// функцию только в момент выполнения этой строки, а к более ранним вызовам в том же блоке
// она ещё не будет существовать ("Call to undefined function").
function import_column_select(string $name, array $columns, array $guesses): string {
  $best = '';
  foreach ($guesses as $g) { foreach ($columns as $c) { if (stripos($c, $g) !== false) { $best = $c; break 2; } } }
  $html = '<select name="' . $name . '"><option value="">— не импортировать —</option>';
  foreach ($columns as $c) { $html .= '<option value="' . htmlspecialchars($c) . '"' . ($c === $best ? ' selected' : '') . '>' . htmlspecialchars($c) . '</option>'; }
  return $html . '</select>';
}

// ВАЖНО: вся обработка POST (и любой redirect()) — ниже, ДО require_once _layout_start.php
// в самом конце этого блока. _layout_start.php сразу выводит HTML шапки сайта, а
// header('Location') внутри redirect() физически не срабатывает, если хоть байт ответа уже
// отправлен браузеру — страница обрывается на середине без подтверждения. Поймал ровно эту
// ошибку живым тестом на admin/bbcode_tags.php (тот же паттерн) и здесь исправил сразу же.

// ---- Шаг 1: загрузили файл — определяем, SQL это или CSV, показываем выбор таблицы/маппинга ----
$detectedTables = [];
$detectedColumns = [];
$fileContent = null;
$isSql = false;
$selectedTable = trim((string)($_POST['table_name'] ?? $_GET['table_name'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'detect') {
  csrf_verify();
  if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
    $fileContent = file_get_contents($_FILES['import_file']['tmp_name']);
  } else {
    $fileContent = (string)($_POST['pasted_content'] ?? '');
  }
  if (trim((string)$fileContent) === '') {
    flash_set('error', 'Загрузите файл или вставьте содержимое в текстовое поле.');
    redirect('/admin/import.php');
  }
  // Кладём во временное хранилище сессии — форма маппинга колонок отправляется отдельным
  // шагом, повторно просить перезалить файл неудобно.
  $_SESSION['import_pending_content'] = $fileContent;
  $isSql = import_looks_like_sql($fileContent);
  if ($isSql) {
    $detectedTables = import_detect_sql_tables($fileContent);
    if (!$detectedTables) { flash_set('error', 'В файле не найдено ни одного INSERT INTO — это точно SQL-дамп?'); redirect('/admin/import.php'); }
  } else {
    $rows = import_parse_csv($fileContent);
    $detectedColumns = $rows ? array_keys($rows[0]) : [];
    if (!$detectedColumns) { flash_set('error', 'Не удалось разобрать CSV — проверьте, что первая строка это заголовки колонок.'); redirect('/admin/import.php'); }
  }
} elseif (!empty($_SESSION['import_pending_content']) && $selectedTable) {
  $fileContent = $_SESSION['import_pending_content'];
  $isSql = true;
  $rows = import_parse_sql_inserts($fileContent, $selectedTable);
  $detectedColumns = $rows ? array_keys($rows[0]) : [];
} elseif (!empty($_SESSION['import_pending_content']) && !import_looks_like_sql($_SESSION['import_pending_content'])) {
  $fileContent = $_SESSION['import_pending_content'];
  $rows = import_parse_csv($fileContent);
  $detectedColumns = $rows ? array_keys($rows[0]) : [];
}

// ---- Шаг 2: колонки размечены — реально импортируем ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'import') {
  csrf_verify();
  $fileContent = $_SESSION['import_pending_content'] ?? '';
  $kind = $_POST['kind'] ?? '';
  $tableName = trim((string)($_POST['table_name'] ?? ''));

  $rows = $tableName !== '' && import_looks_like_sql($fileContent)
    ? import_parse_sql_inserts($fileContent, $tableName)
    : import_parse_csv($fileContent);

  if (!$rows) {
    flash_set('error', 'Не удалось получить строки для импорта — попробуйте загрузить файл заново.');
    redirect('/admin/import.php');
  }

  $result = null;
  if ($kind === 'users') {
    $result = import_users($rows, ['username' => $_POST['map_username'] ?? '', 'email' => $_POST['map_email'] ?? '', 'created_at' => $_POST['map_created_at'] ?? '']);
  } elseif ($kind === 'forum_threads') {
    $categoryId = (int)($_POST['category_id'] ?? 0);
    if (!$categoryId) { flash_set('error', 'Выберите категорию форума для импортируемых тем.'); redirect('/admin/import.php'); }
    $result = import_forum_threads($rows, [
      'title' => $_POST['map_title'] ?? '', 'message' => $_POST['map_message'] ?? '',
      'author_username' => $_POST['map_author_username'] ?? '', 'created_at' => $_POST['map_created_at'] ?? '',
    ], $categoryId, $__user['id']);
  } elseif ($kind === 'videos') {
    $channelId = (int)($_POST['channel_id'] ?? 0);
    if (!$channelId) { flash_set('error', 'Выберите канал для импортируемых видео.'); redirect('/admin/import.php'); }
    $result = import_videos($rows, [
      'title' => $_POST['map_title'] ?? '', 'source_url' => $_POST['map_source_url'] ?? '',
      'description' => $_POST['map_description'] ?? '', 'thumbnail_url' => $_POST['map_thumbnail_url'] ?? '',
    ], $channelId, $__user['id']);
  }

  unset($_SESSION['import_pending_content']);
  if ($result) {
    $msg = "Готово: создано {$result['created']}, пропущено {$result['skipped']} (дубликаты/пустые поля).";
    if ($result['errors']) $msg .= ' Первые ошибки: ' . implode('; ', $result['errors']);
    flash_set($result['created'] > 0 ? 'success' : 'error', $msg);
  }
  redirect('/admin/import.php');
}

$forumCategories = [];
try { $forumCategories = db()->query('SELECT id, title FROM forum_categories ORDER BY title')->fetchAll(); } catch (\Throwable $e) { }
$channels = [];
try { $channels = db()->query('SELECT id, title FROM channels ORDER BY title')->fetchAll(); } catch (\Throwable $e) { }

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Импорт данных из XenForo / PlayTube / другого движка</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:680px">
  Настоящий импорт настоящего экспорта — не ручной ввод строк. Два формата на входе:<br>
  1) <b>SQL-дамп</b> (файл .sql от phpMyAdmin "Export" или консольного <code>mysqldump</code>) —
  система сама найдёт все таблицы с <code>INSERT INTO</code> и даст выбрать нужную.<br>
  2) <b>CSV</b> (phpMyAdmin "Export → CSV" для одной таблицы) — первая строка должна быть
  заголовками колонок.<br>
  Дальше — сопоставляете колонки источника с нашими полями (имена колонок отличаются между
  версиями XenForo и уж тем более у PlayTube — поэтому это не жёстко зашито в код, а
  настраивается на этом шаге).
</p>

<?php if (!$detectedColumns && !$detectedTables): ?>
<div class="form-card form-wide">
  <h3 style="margin-top:0">Шаг 1 — загрузите файл</h3>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="detect">
    <label>Файл (.sql или .csv)</label>
    <input type="file" name="import_file" accept=".sql,.csv,.txt">
    <label style="margin-top:10px">…или вставьте содержимое прямо сюда</label>
    <textarea name="pasted_content" rows="8" placeholder="INSERT INTO `xf_user` (...) VALUES (...);&#10;или CSV с заголовками в первой строке"></textarea>
    <button class="btn btn-primary" type="submit" style="margin-top:10px">Разобрать файл</button>
  </form>
</div>
<?php elseif ($isSql && $detectedTables && !$detectedColumns): ?>
<div class="form-card form-wide">
  <h3 style="margin-top:0">Шаг 2 — выберите таблицу</h3>
  <p style="font-size:13px;color:var(--text-dim)">Найдено таблиц с данными: <?= count($detectedTables) ?></p>
  <form method="GET">
    <select name="table_name">
      <?php foreach ($detectedTables as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit" style="margin-top:10px">Далее</button>
  </form>
  <p style="font-size:12px;color:var(--text-dim);margin-top:10px">
    Типичные имена таблиц в XenForo: <code>xf_user</code> (пользователи), <code>xf_thread</code>
    (темы форума), <code>xf_post</code> (сообщения). У PlayTube и других движков — смотрите
    список выше, он построен по реальному содержимому вашего файла.
  </p>
</div>
<?php elseif ($detectedColumns): ?>
<div class="form-card form-wide">
  <h3 style="margin-top:0">Шаг 3 — что импортируем и как сопоставить колонки</h3>
  <p style="font-size:12.5px;color:var(--text-dim)">Найденные колонки: <?= e(implode(', ', $detectedColumns)) ?></p>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="import">
    <input type="hidden" name="table_name" value="<?= e($selectedTable) ?>">
    <label>Что импортируем</label>
    <select name="kind" id="import-kind" onchange="document.querySelectorAll('.import-kind-block').forEach(b=>b.style.display='none'); document.getElementById('kind-'+this.value).style.display='block'">
      <option value="users">Пользователи</option>
      <option value="forum_threads">Темы форума (+ первое сообщение)</option>
      <option value="videos">Видео (для видеохостинга)</option>
    </select>

    <div id="kind-users" class="import-kind-block" style="margin-top:14px">
      <label>Колонка с ником</label><?= import_column_select('map_username', $detectedColumns, ['username', 'user_name', 'login']) ?>
      <label>Колонка с email</label><?= import_column_select('map_email', $detectedColumns, ['email', 'user_email']) ?>
      <label>Колонка с датой регистрации</label><?= import_column_select('map_created_at', $detectedColumns, ['register_date', 'created_at', 'joined']) ?>
      <p style="font-size:11.5px;color:var(--text-dim);margin-top:8px">Пароли не переносятся (хеш другого движка мы не проверим) — импортированным нужно будет сбросить пароль через администратора.</p>
    </div>

    <div id="kind-forum_threads" class="import-kind-block" style="margin-top:14px;display:none">
      <label>Категория форума, куда добавить темы</label>
      <select name="category_id">
        <?php foreach ($forumCategories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option><?php endforeach; ?>
      </select>
      <label>Колонка с заголовком темы</label><?= import_column_select('map_title', $detectedColumns, ['title', 'thread_title']) ?>
      <label>Колонка с текстом первого сообщения</label><?= import_column_select('map_message', $detectedColumns, ['message', 'post_body', 'content']) ?>
      <label>Колонка с ником автора</label><?= import_column_select('map_author_username', $detectedColumns, ['username', 'author']) ?>
      <label>Колонка с датой создания</label><?= import_column_select('map_created_at', $detectedColumns, ['post_date', 'created_at']) ?>
      <p style="font-size:11.5px;color:var(--text-dim);margin-top:8px">Если автор темы не найден среди уже импортированных/существующих пользователей — тема будет создана от вашего имени.</p>
    </div>

    <div id="kind-videos" class="import-kind-block" style="margin-top:14px;display:none">
      <label>Канал, куда добавить видео</label>
      <select name="channel_id">
        <?php foreach ($channels as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option><?php endforeach; ?>
      </select>
      <label>Колонка с названием видео</label><?= import_column_select('map_title', $detectedColumns, ['title', 'video_title']) ?>
      <label>Колонка со ссылкой на видео</label><?= import_column_select('map_source_url', $detectedColumns, ['url', 'video_url', 'source']) ?>
      <label>Колонка с описанием (необязательно)</label><?= import_column_select('map_description', $detectedColumns, ['description', 'desc']) ?>
      <label>Колонка с превью (необязательно)</label><?= import_column_select('map_thumbnail_url', $detectedColumns, ['thumbnail', 'thumb', 'poster']) ?>
    </div>

    <button class="btn btn-primary" type="submit" style="margin-top:14px">Импортировать</button>
  </form>
</div>
<?php
endif; ?>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
