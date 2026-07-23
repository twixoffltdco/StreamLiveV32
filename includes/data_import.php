<?php
// Реальный импорт данных из XenForo / PlayTube / любого другого движка — не ручной ввод
// строк, а разбор настоящего экспорта (SQL-дамп с INSERT INTO, либо CSV, который можно
// выгрузить через phpMyAdmin "Export → CSV" из любой версии XenForo или PlayTube). Работает
// в два шага: 1) распознать структуру (какие есть таблицы/колонки), 2) дать сопоставить эти
// колонки с нашими полями (username → username, register_date → created_at и т.д.) —
// это надёжнее, чем жёстко зашивать в код точные названия колонок одной конкретной версии
// XenForo, потому что они отличаются между 1.x/2.0/2.2 и уж тем более у обособленного PlayTube.

// ---- Разбор SQL INSERT-дампа (без полноценного SQL-парсера — но достаточно надёжно для
// экспортов из phpMyAdmin: обрабатывает 'строки', "строки", экранирование \' и удвоенное '',
// NULL, числа, и несколько кортежей (...),(...),(...) в одном INSERT). ----

// Находит все `INSERT INTO \`table\` (col1,col2,...) VALUES (...), (...);` для конкретной
// таблицы и возвращает список строк в виде ассоц.массивов [колонка => значение].
function import_parse_sql_inserts(string $sql, string $tableName): array {
  $rows = [];
  $tableEscaped = preg_quote($tableName, '/');
  // INSERT INTO `table` (`col1`, `col2`, ...) VALUES (...), (...), ...;
  if (!preg_match_all(
    '/INSERT\s+INTO\s+`?' . $tableEscaped . '`?\s*\(([^)]+)\)\s*VALUES\s*(.+?);/is',
    $sql, $statementMatches, PREG_SET_ORDER
  )) {
    return [];
  }

  foreach ($statementMatches as $stmt) {
    $columns = array_map(function ($c) { return trim(trim($c), '`"\' '); }, explode(',', $stmt[1]));
    $tuples = import_split_value_tuples($stmt[2]);
    foreach ($tuples as $tuple) {
      $values = import_parse_value_tuple($tuple);
      if (count($values) !== count($columns)) continue; // испорченная/неполная строка — пропускаем, не роняем весь импорт
      $rows[] = array_combine($columns, $values);
    }
  }
  return $rows;
}

// Разбивает "(...), (...), (...)" на отдельные "(...)" — учитывая кавычки, чтобы запятая
// или закрывающая скобка ВНУТРИ строкового значения не сломала разделение кортежей.
function import_split_value_tuples(string $valuesBlock): array {
  $tuples = [];
  $depth = 0;
  $current = '';
  $inString = false;
  $quoteChar = '';
  $len = strlen($valuesBlock);
  for ($i = 0; $i < $len; $i++) {
    $ch = $valuesBlock[$i];
    if ($inString) {
      $current .= $ch;
      if ($ch === '\\' && $i + 1 < $len) { $current .= $valuesBlock[++$i]; continue; } // экранированный символ — берём как есть
      if ($ch === $quoteChar) $inString = false;
      continue;
    }
    if ($ch === "'" || $ch === '"') { $inString = true; $quoteChar = $ch; $current .= $ch; continue; }
    if ($ch === '(') { $depth++; if ($depth === 1) { $current = ''; continue; } }
    if ($ch === ')') {
      $depth--;
      if ($depth === 0) { $tuples[] = $current; continue; }
    }
    if ($depth > 0) $current .= $ch;
  }
  return $tuples;
}

// Разбирает содержимое ОДНОГО кортежа "'a', 'b', NULL, 123" в массив PHP-значений.
function import_parse_value_tuple(string $tuple): array {
  $values = [];
  $current = '';
  $inString = false;
  $quoteChar = '';
  $len = strlen($tuple);
  for ($i = 0; $i < $len; $i++) {
    $ch = $tuple[$i];
    if ($inString) {
      if ($ch === '\\' && $i + 1 < $len) { $current .= $tuple[++$i]; continue; }
      if ($ch === $quoteChar) {
        // Удвоенная кавычка '' внутри строки — экранирование, а не конец строки (стандарт SQL)
        if ($i + 1 < $len && $tuple[$i + 1] === $quoteChar) { $current .= $quoteChar; $i++; continue; }
        $inString = false;
        continue;
      }
      $current .= $ch;
      continue;
    }
    if ($ch === "'" || $ch === '"') { $inString = true; $quoteChar = $ch; continue; }
    if ($ch === ',') { $values[] = import_normalize_sql_literal($current); $current = ''; continue; }
    $current .= $ch;
  }
  $values[] = import_normalize_sql_literal($current);
  return $values;
}

function import_normalize_sql_literal(string $raw): ?string {
  $trimmed = trim($raw);
  if (strtoupper($trimmed) === 'NULL' || $trimmed === '') return null;
  return $trimmed;
}

// ---- Разбор CSV (универсальный путь — работает для ЛЮБОГО движка, если экспортировать
// таблицу через phpMyAdmin "Export → CSV" вместо SQL) ----

function import_parse_csv(string $csv): array {
  $lines = preg_split('/\r\n|\r|\n/', trim($csv));
  if (count($lines) < 2) return [];
  $header = str_getcsv(array_shift($lines));
  $header = array_map('trim', $header);
  $rows = [];
  foreach ($lines as $line) {
    if (trim($line) === '') continue;
    $cells = str_getcsv($line);
    if (count($cells) !== count($header)) continue; // битая строка — пропускаем, не роняем импорт
    $rows[] = array_combine($header, $cells);
  }
  return $rows;
}

// ---- Импорт в наши таблицы по карте соответствия колонок ----

// $columnMap — ['наше_поле' => 'колонка_в_источнике'], например ['username' => 'username',
// 'email' => 'user_email', 'created_at' => 'register_date']. Поля, для которых не задано
// соответствие (пусто в форме), берут разумное значение по умолчанию.
function import_users(array $rows, array $columnMap): array {
  $created = 0; $skipped = 0; $errors = [];
  $stmt = db()->prepare(
    'INSERT INTO users (username, email, password_hash, created_at) VALUES (?, ?, ?, ?)'
  );
  $checkStmt = db()->prepare('SELECT id FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?)');

  foreach ($rows as $row) {
    $username = trim((string)($row[$columnMap['username'] ?? ''] ?? ''));
    if ($username === '') { $skipped++; continue; }
    // Ограничиваем длину и вычищаем то, что не пройдёт наш UNIQUE VARCHAR(64)/формат ника
    $username = mb_substr(preg_replace('/[^a-zA-Zа-яА-Я0-9_\-]/u', '_', $username), 0, 64);
    $email = trim((string)($row[$columnMap['email'] ?? ''] ?? '')) ?: null;
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = null;
    $createdAtRaw = trim((string)($row[$columnMap['created_at'] ?? ''] ?? ''));
    $createdAt = import_normalize_datetime($createdAtRaw) ?? date('Y-m-d H:i:s');

    $checkStmt->execute([$username, $email]);
    if ($checkStmt->fetch()) { $skipped++; continue; }

    try {
      // Импортированным пользователям НЕ переносим пароль как есть (хеши XenForo/PlayTube
      // используют другой алгоритм — password_verify() с bcrypt их не проверит, и хранить
      // чужой хеш от другого алгоритма без возможности его проверить — просто мёртвый груз).
      // Ставим случайный пароль и помечаем на смену через "Забыли пароль" — это правильнее,
      // чем создавать аккаунт, в который никто не сможет зайти без сброса пароля через админа.
      $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
      $stmt->execute([$username, $email, $randomPassword, $createdAt]);
      $created++;
    } catch (\Throwable $e) {
      $skipped++;
      if (count($errors) < 5) $errors[] = $username . ': ' . $e->getMessage();
    }
  }
  return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
}

function import_forum_threads(array $rows, array $columnMap, int $categoryId, int $fallbackUserId): array {
  $created = 0; $skipped = 0; $errors = [];
  $findUser = db()->prepare('SELECT id FROM users WHERE username = ?');
  $insertThread = db()->prepare('INSERT INTO forum_threads (category_id, user_id, title, created_at, last_post_at) VALUES (?, ?, ?, ?, ?)');
  $insertPost = db()->prepare('INSERT INTO forum_posts (thread_id, user_id, message, created_at) VALUES (?, ?, ?, ?)');

  foreach ($rows as $row) {
    $title = trim((string)($row[$columnMap['title'] ?? ''] ?? ''));
    if ($title === '') { $skipped++; continue; }
    $title = mb_substr($title, 0, 200);
    $message = (string)($row[$columnMap['message'] ?? ''] ?? '');
    $authorName = trim((string)($row[$columnMap['author_username'] ?? ''] ?? ''));
    $createdAt = import_normalize_datetime((string)($row[$columnMap['created_at'] ?? ''] ?? '')) ?? date('Y-m-d H:i:s');

    $authorId = $fallbackUserId;
    if ($authorName !== '') {
      $findUser->execute([$authorName]);
      $found = $findUser->fetch();
      if ($found) $authorId = (int)$found['id'];
    }

    try {
      $insertThread->execute([$categoryId, $authorId, $title, $createdAt, $createdAt]);
      $threadId = (int)db()->lastInsertId();
      if ($message !== '') {
        $insertPost->execute([$threadId, $authorId, $message, $createdAt]);
      }
      $created++;
    } catch (\Throwable $e) {
      $skipped++;
      if (count($errors) < 5) $errors[] = $title . ': ' . $e->getMessage();
    }
  }
  return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
}

function import_videos(array $rows, array $columnMap, int $channelId, int $fallbackUserId): array {
  require_once __DIR__ . '/video_embed.php';
  $created = 0; $skipped = 0; $errors = [];
  $insertVideo = db()->prepare(
    'INSERT INTO videos (channel_id, user_id, slug, source_url, platform, embed_url, title, description, tags, thumbnail_url, status, meta_source)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'published\', \'manual\')'
  );

  foreach ($rows as $row) {
    $title = trim((string)($row[$columnMap['title'] ?? ''] ?? ''));
    $sourceUrl = trim((string)($row[$columnMap['source_url'] ?? ''] ?? ''));
    if ($title === '' || $sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) { $skipped++; continue; }

    $platform = detect_video_platform($sourceUrl) ?? 'iframe';
    $embedUrl = normalize_video_embed($platform, $sourceUrl);
    $description = (string)($row[$columnMap['description'] ?? ''] ?? '');
    $thumbnail = trim((string)($row[$columnMap['thumbnail_url'] ?? ''] ?? '')) ?: null;
    $slugBase = mb_substr(preg_replace('/[^a-z0-9\-]/', '-', mb_strtolower($title)), 0, 60);
    $slug = trim($slugBase, '-') ?: ('video-' . bin2hex(random_bytes(4)));

    try {
      $uniqueSlug = $slug . '-' . substr(md5(uniqid('', true)), 0, 6); // гарантируем уникальность без коллизий при массовом импорте
      $insertVideo->execute([$channelId, $fallbackUserId, $uniqueSlug, $sourceUrl, $platform, $embedUrl, mb_substr($title, 0, 255), $description, '', $thumbnail]);
      $created++;
    } catch (\Throwable $e) {
      $skipped++;
      if (count($errors) < 5) $errors[] = $title . ': ' . $e->getMessage();
    }
  }
  return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
}

// XenForo хранит даты как UNIX-timestamp (число секунд), большинство остальных движков —
// как обычную строку даты. Пробуем оба варианта.
function import_normalize_datetime(string $raw): ?string {
  $raw = trim($raw);
  if ($raw === '') return null;
  if (ctype_digit($raw) && (int)$raw > 0) {
    // Похоже на UNIX-timestamp (секунды) — типичный формат XenForo (register_date, post_date)
    return date('Y-m-d H:i:s', (int)$raw);
  }
  $ts = strtotime($raw);
  return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
}

// Определяет, что за файл загрузили — SQL-дамп или CSV, и достаёт из SQL-дампа список
// имён таблиц (по "INSERT INTO `table`") — для формы выбора "какую таблицу импортировать".
function import_detect_sql_tables(string $content): array {
  if (!preg_match_all('/INSERT\s+INTO\s+`?(\w+)`?/i', $content, $m)) return [];
  return array_values(array_unique($m[1]));
}

function import_looks_like_sql(string $content): bool {
  return stripos($content, 'INSERT INTO') !== false;
}
