<?php
// Импорт пользователей из CSV — универсальный, не завязан на конкретную версию
// XenForo или PlayTube. Экспортировать CSV можно из phpMyAdmin: открыть таблицу
// пользователей (в XenForo обычно xf_user, в PlayTube — users) → Export → CSV.
//
// Почему не парсим сразу структуру XenForo/PlayTube напрямую: у разных версий
// разные названия колонок, и без реального дампа под рукой угадывание с высокой
// вероятностью просто не сработает и молча даст мусор в базе. Ручное сопоставление
// колонок — тот же подход, что у серьёзных инструментов миграции (тот же импорт
// в самом XenForo из vBulletin работает через сопоставление полей).
require_once __DIR__ . '/../includes/auth.php';
$__user = require_admin();

const IMPORT_TARGET_FIELDS = [
  'username'    => 'Логин (обязательно)',
  'email'       => 'Email (обязательно)',
  'password_hash' => 'Хеш пароля (опционально — без него ставим случайный пароль + флаг сброса)',
  'real_name'   => 'Отображаемое имя (опционально)',
  'created_at'  => 'Дата регистрации (опционально, формат YYYY-MM-DD)',
];

// Определяем формат хеша пароля по виду строки — не по названию платформы-источника,
// это надёжнее (одна и та же платформа может хранить пароли по-разному в зависимости
// от версии/настроек). password_hash()-совместимые ($2y$/$2a$/$2b$ — bcrypt) переносим
// как есть, они будут сразу рабочими. Остальное (MD5/SHA1/что угодно нераспознанное)
// считаем несовместимым — пользователю ставится случайный пароль и флаг обязательной
// смены при первом входе (тот же механизм, что уже есть для админ-сброса пароля).
function detect_hash_compat(string $hash): string {
  $hash = trim($hash);
  if ($hash === '') return 'empty';
  if (preg_match('/^\$2[aby]\$/', $hash)) return 'bcrypt_compatible';
  if (preg_match('/^[a-f0-9]{32}$/i', $hash)) return 'md5_legacy';
  if (preg_match('/^[a-f0-9]{40}$/i', $hash)) return 'sha1_legacy';
  if (preg_match('/^[a-f0-9]{64}$/i', $hash)) return 'sha256_legacy';
  return 'unknown';
}

$step = $_POST['step'] ?? ($_FILES['csv_file'] ?? null ? 'preview' : 'upload');
$error = null;
$preview = null;
$importResult = null;

if ($step === 'preview' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
  csrf_verify();
  $tmpPath = $_FILES['csv_file']['tmp_name'];
  $rows = [];
  if (($fh = fopen($tmpPath, 'r')) !== false) {
    $header = fgetcsv($fh);
    $i = 0;
    while (($row = fgetcsv($fh)) !== false && $i < 5) { $rows[] = $row; $i++; }
    fclose($fh);
  }
  if (!$header) {
    $error = 'Не удалось прочитать CSV — проверь, что файл в формате CSV с заголовками в первой строке';
  } else {
    // Сохраняем путь к файлу во временную сессионную область на время сопоставления колонок
    $storedPath = sys_get_temp_dir() . '/import_' . bin2hex(random_bytes(8)) . '.csv';
    move_uploaded_file($tmpPath, $storedPath);
    $_SESSION['import_csv_path'] = $storedPath;
    $_SESSION['import_csv_header'] = $header;
    $preview = ['header' => $header, 'rows' => $rows];
  }
} elseif ($step === 'run' && !empty($_SESSION['import_csv_path']) && file_exists($_SESSION['import_csv_path'])) {
  csrf_verify();
  $mapping = $_POST['mapping'] ?? [];
  $header = $_SESSION['import_csv_header'];
  $path = $_SESSION['import_csv_path'];

  $colIndex = [];
  foreach (IMPORT_TARGET_FIELDS as $field => $label) {
    $sourceCol = $mapping[$field] ?? '';
    $colIndex[$field] = $sourceCol !== '' ? array_search($sourceCol, $header, true) : false;
  }

  if ($colIndex['username'] === false || $colIndex['email'] === false) {
    $error = 'Обязательно сопоставь колонки для логина и email';
  } else {
    $imported = 0; $skipped = 0; $needsPasswordReset = 0; $errors = [];
    $fh = fopen($path, 'r');
    fgetcsv($fh); // пропускаем заголовок
    $rowNum = 1;
    while (($row = fgetcsv($fh)) !== false) {
      $rowNum++;
      $username = trim((string)($row[$colIndex['username']] ?? ''));
      $email = trim((string)($row[$colIndex['email']] ?? ''));
      if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipped++; $errors[] = "строка {$rowNum}: пустой логин или некорректный email — пропущена";
        continue;
      }

      $exists = db()->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
      $exists->execute([$username, $email]);
      if ($exists->fetch()) {
        $skipped++; $errors[] = "строка {$rowNum}: @{$username} или {$email} уже есть на платформе — пропущена";
        continue;
      }

      $sourceHash = $colIndex['password_hash'] !== false ? (string)($row[$colIndex['password_hash']] ?? '') : '';
      $compat = detect_hash_compat($sourceHash);
      $mustChangePassword = 1;
      if ($compat === 'bcrypt_compatible') {
        $passwordHash = $sourceHash;
        $mustChangePassword = 0; // старый пароль реально сработает без вмешательства
      } else {
        $passwordHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
        $needsPasswordReset++;
      }

      $realName = $colIndex['real_name'] !== false ? trim((string)($row[$colIndex['real_name']] ?? '')) : '';
      $createdAt = null;
      if ($colIndex['created_at'] !== false) {
        $raw = trim((string)($row[$colIndex['created_at']] ?? ''));
        $ts = $raw !== '' ? strtotime($raw) : false;
        if ($ts !== false) $createdAt = date('Y-m-d H:i:s', $ts);
      }

      $stmt = db()->prepare(
        'INSERT INTO users (username, email, password_hash, must_change_password, created_at)
         VALUES (?, ?, ?, ?, ?)'
      );
      try {
        $stmt->execute([$username, $email, $passwordHash, $mustChangePassword, $createdAt ?: date('Y-m-d H:i:s')]);
        $imported++;
      } catch (\Throwable $e) {
        $skipped++; $errors[] = "строка {$rowNum}: ошибка записи — " . $e->getMessage();
      }
    }
    fclose($fh);
    @unlink($path);
    unset($_SESSION['import_csv_path'], $_SESSION['import_csv_header']);

    $importResult = compact('imported', 'skipped', 'needsPasswordReset', 'errors');
  }
}

$pageTitle = 'Импорт пользователей';
require_once __DIR__ . '/_layout_start.php';
?>
<h1>Импорт пользователей из CSV</h1>
<p style="color:var(--text-dim);font-size:13px">
  Экспортируй таблицу пользователей из XenForo/PlayTube (или откуда угодно) в CSV через
  phpMyAdmin → Экспорт → формат CSV, с заголовками колонок в первой строке.
</p>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($importResult): ?>
  <div class="alert alert-success">
    Импортировано: <?= (int)$importResult['imported'] ?> ·
    Пропущено: <?= (int)$importResult['skipped'] ?> ·
    Со сброшенным паролем (нужно передать новый или попросить восстановить): <?= (int)$importResult['needsPasswordReset'] ?>
  </div>
  <?php if ($importResult['errors']): ?>
    <details><summary>Подробности по пропущенным (<?= count($importResult['errors']) ?>)</summary>
      <ul style="font-size:12px;color:var(--text-dim)"><?php foreach ($importResult['errors'] as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
<?php endif; ?>

<?php if ($preview): ?>
  <form method="POST" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="run">
    <h3>Сопоставь колонки</h3>
    <?php foreach (IMPORT_TARGET_FIELDS as $field => $label): ?>
      <label><?= e($label) ?></label>
      <select name="mapping[<?= e($field) ?>]" style="width:100%;padding:8px;margin-bottom:10px">
        <option value="">— не использовать —</option>
        <?php foreach ($preview['header'] as $col): ?>
          <option value="<?= e($col) ?>" <?= strcasecmp($col, $field) === 0 ? 'selected' : '' ?>><?= e($col) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endforeach; ?>

    <h3>Предпросмотр (первые <?= count($preview['rows']) ?> строк)</h3>
    <div style="overflow-x:auto"><table class="admin-table" style="width:100%;min-width:500px">
      <thead><tr><?php foreach ($preview['header'] as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($preview['rows'] as $row): ?><tr><?php foreach ($row as $cell): ?><td><?= e((string)$cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>

    <p style="color:var(--text-dim);font-size:12px">
      Пароли переносятся напрямую, только если это bcrypt-хеш (начинается с $2y$/$2a$/$2b$ —
      так хранит пароли и наша платформа, и XenForo 2.x, и большинство современных PHP-скриптов).
      Остальные форматы (MD5, SHA1, что-то нераспознанное) не совместимы напрямую — таким
      пользователям ставится случайный пароль и флаг обязательной смены при первом входе
      (через админ-сброс пароля, уже есть на платформе).
    </p>
    <button type="submit" class="btn btn-primary">Импортировать</button>
  </form>
<?php else: ?>
  <form method="POST" enctype="multipart/form-data" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="preview">
    <label>CSV-файл</label>
    <input type="file" name="csv_file" accept=".csv" required style="width:100%;padding:10px;margin:8px 0">
    <button type="submit" class="btn btn-primary">Загрузить и посмотреть колонки</button>
  </form>
<?php endif; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
