<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/embed_helper.php';

// Чинит уже сохранённые "сырые" ссылки YouTube/VK/Rutube в существующих источниках —
// те, что были добавлены ДО того, как появилась автонормализация при сохранении.
function run_url_fix(PDO $pdo): array {
  $log = [];
  try {
    $rows = $pdo->query("SELECT id, type, url FROM sources WHERE type IN ('youtube','vk','rutube')")->fetchAll();
    $fixed = 0;
    foreach ($rows as $r) {
      $normalized = normalize_embed_url($r['type'], $r['url']);
      if ($normalized !== $r['url']) {
        $pdo->prepare('UPDATE sources SET url = ? WHERE id = ?')->execute([$normalized, $r['id']]);
        $fixed++;
      }
    }
    $log[] = $fixed > 0 ? "✓ Исправлено ссылок на embed-формат: {$fixed}" : '– Все ссылки уже в правильном embed-формате';
  } catch (Exception $e) {
    $log[] = '✗ Проверка ссылок: ' . $e->getMessage();
  }
  return $log;
}

// Принудительно переключает БД и все известные таблицы на utf8mb4 — безопасно перезапускать сколько угодно раз.
function run_charset_fix(PDO $pdo): array {
  $log = [];
  try {
    $pdo->exec('ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $log[] = '✓ База данных → utf8mb4';
  } catch (Exception $e) {
    $log[] = '⚠ База данных: ' . $e->getMessage() . ' (недостаточно прав, не критично)';
  }

  $tables = ['users','oauth_providers','sources','channels','schedule','chat_moderators','chat_bans',
             'stickers','chat_messages','notifications','comments','settings','schema_migrations',
             'forum_categories','forum_threads','forum_posts','forum_moderators',
             'channel_likes','favorites','short_likes','rss_sources','rss_items',
             'ai_conversations','ai_messages','ai_projects','ai_usage'];
  foreach ($tables as $t) {
    try {
      $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t));
      if ($stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $log[] = "✓ Таблица {$t} → utf8mb4";
      }
    } catch (Exception $e) {
      $log[] = "✗ Таблица {$t}: " . $e->getMessage();
    }
  }
  return $log;
}

// Применяет ещё не применённые файлы из sql/migrations/*.sql в порядке имени файла.
function run_migrations(PDO $pdo): array {
  $log = [];
  $pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
       id INT AUTO_INCREMENT PRIMARY KEY,
       filename VARCHAR(255) UNIQUE NOT NULL,
       applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
  );

  $files = glob(__DIR__ . '/../sql/migrations/*.sql');
  sort($files);

  foreach ($files as $file) {
    $name = basename($file);
    $stmt = $pdo->prepare('SELECT id FROM schema_migrations WHERE filename = ?');
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
      $log[] = "– {$name} (уже применена)";
      continue;
    }
    try {
      $pdo->exec(file_get_contents($file));
      $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
      $log[] = "✓ {$name} применена";
    } catch (Exception $e) {
      $log[] = "✗ {$name}: " . $e->getMessage();
    }
  }
  return $log;
}

// Список миграций и их статус — для отображения в админке
function list_migrations(PDO $pdo): array {
  $applied = [];
  try {
    $rows = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($rows);
  } catch (Exception $e) { /* таблицы миграций ещё нет — все считаются неприменёнными */ }

  $files = glob(__DIR__ . '/../sql/migrations/*.sql');
  sort($files);
  $result = [];
  foreach ($files as $file) {
    $name = basename($file);
    $result[] = ['filename' => $name, 'applied' => isset($applied[$name])];
  }
  return $result;
}
