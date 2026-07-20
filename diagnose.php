<?php
// Открой https://твой-домен/diagnose.php в браузере и пришли мне то, что выведется.
// Ничего не меняет на сайте, только проверяет. Можно удалить после того, как всё почини.

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><meta charset='utf-8'><body style='background:#0b0b10;color:#eee;font-family:monospace;padding:20px;line-height:1.7'>";
echo "<h2>StreamLive — диагностика</h2>";

function ok($label) { echo "<div style='color:#2ee6a8'>✓ $label</div>"; }
function bad($label) { echo "<div style='color:#ff4d4f'>✗ $label</div>"; }
function info($label) { echo "<div style='color:#9a9aac'>… $label</div>"; }

echo "<h3>1. PHP</h3>";
echo "<div>Версия PHP: " . PHP_VERSION . "</div>";
foreach (['pdo_mysql', 'mbstring', 'curl', 'session'] as $ext) {
  extension_loaded($ext) ? ok("расширение $ext") : bad("расширение $ext ОТСУТСТВУЕТ — поставь через панель хостинга (Select PHP Extensions / PHP Version Manager)");
}

echo "<h3>2. Файлы проекта</h3>";
$expectedFiles = [
  'index.php', 'catalog.php', 'channel.php', 'channel_manage.php', 'new_channel.php', 'dashboard.php',
  'embed.php', 'comment_add.php', 'comment_delete.php', 'shorts.php',
  'chat_send.php', 'chat_poll.php', 'chat_delete.php', 'chat_ban.php',
  'includes/db.php', 'includes/auth.php', 'includes/functions.php', 'includes/oauth.php',
  'includes/header.php', 'includes/footer.php', 'includes/migrations.php',
  'admin/index.php', 'admin/moderation.php', 'admin/channels.php', 'admin/sources.php',
  'admin/oauth.php', 'admin/users.php', 'admin/update.php', 'admin/_layout_start.php', 'admin/_layout_end.php',
  'auth/login.php', 'auth/register.php', 'auth/logout.php', 'auth/oauth_start.php', 'auth/oauth_callback.php',
  'install/index.php', 'config/config.php', 'assets/css/style.css',
  'sql/schema.sql', 'sql/migrations/001_add_comments.sql',
];
$missing = [];
foreach ($expectedFiles as $f) {
  if (file_exists(__DIR__ . '/' . $f)) { ok($f); } else { bad("$f — ФАЙЛ ОТСУТСТВУЕТ"); $missing[] = $f; }
}
if ($missing) {
  echo "<p style='color:#ffc107'>Не хватает " . count($missing) . " файлов — похоже, залилось не всё содержимое архива, или залилось в подпапку вместо корня сайта. Проверь, что содержимое streamlive-php/ (а не сама папка streamlive-php) лежит прямо в корне (public_html или аналог).</p>";
}

echo "<h3>3. config/config.php</h3>";
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
  bad('config/config.php не найден — либо установка не завершена, либо файл не залился. Зайди на /install/');
} else {
  try {
    require $configFile;
    ok('config.php загрузился без ошибок');
    echo "<div>SITE_NAME = " . (defined('SITE_NAME') ? SITE_NAME : '(не задано)') . "</div>";
    echo "<div>SITE_URL = " . (defined('SITE_URL') ? SITE_URL : '(не задано)') . "</div>";
    echo "<div>DB_HOST = " . (defined('DB_HOST') ? DB_HOST : '(не задано)') . "</div>";
    echo "<div>DB_NAME = " . (defined('DB_NAME') ? DB_NAME : '(не задано)') . "</div>";
  } catch (\Throwable $e) {
    bad('config.php содержит ошибку: ' . $e->getMessage());
  }
}

echo "<h3>4. Подключение к базе данных</h3>";
if (defined('DB_HOST')) {
  try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    ok('подключение к MySQL успешно');

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo "<div>Таблиц в базе: " . count($tables) . "</div>";
    $expectedTables = ['users','oauth_providers','sources','channels','schedule','chat_moderators','chat_bans','stickers','chat_messages','notifications','comments','settings','schema_migrations'];
    foreach ($expectedTables as $t) {
      in_array($t, $tables, true) ? ok("таблица $t") : bad("таблица $t ОТСУТСТВУЕТ — нужно зайти в /admin/update.php и нажать «Обновить базу данных», либо переустановить через /install/");
    }

    if (in_array('users', $tables, true)) {
      $cnt = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
      echo "<div>Пользователей в базе: $cnt</div>";
    }

    if (in_array('channels', $tables, true)) {
      echo "<h4 style='margin-top:14px'>Каналы и их статус (почему может не открываться конкретный канал)</h4>";
      $channels = $pdo->query('SELECT id, slug, title, status, default_source_id FROM channels ORDER BY id')->fetchAll();
      if (empty($channels)) {
        info('каналов в базе пока нет');
      } else {
        foreach ($channels as $c) {
          $statusNote = $c['status'] === 'approved' ? '' : ' — из-за этого статуса /channel.php?slug=... покажет ошибку "не допущен в каталог"';
          $srcNote = $c['default_source_id'] ? '' : ' [БЕЗ источника по умолчанию — в Shorts/на канале будет "эфир недоступен", если нет активного слота расписания]';
          echo "<div>#{$c['id']} {$c['slug']} — статус: <b>{$c['status']}</b>{$statusNote}{$srcNote}</div>";
        }
      }
    }

    // Проверка кодировки таблиц
    $charsetRows = $pdo->query(
      "SELECT TABLE_NAME, CCSA.character_set_name
       FROM information_schema.TABLES T
       JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA ON CCSA.collation_name = T.table_collation
       WHERE T.table_schema = DATABASE()"
    )->fetchAll();
    $wrongCharset = array_filter($charsetRows, fn($r) => $r['character_set_name'] !== 'utf8mb4');
    if ($wrongCharset) {
      bad(count($wrongCharset) . ' таблиц НЕ в utf8mb4 — зайди в /admin/update.php и нажми «Обновить базу данных»');
      foreach ($wrongCharset as $r) { echo "<div style='color:#ffc107'>&nbsp;&nbsp;— {$r['TABLE_NAME']}: {$r['character_set_name']}</div>"; }
    } else {
      ok('все таблицы в utf8mb4');
    }
  } catch (\Throwable $e) {
    bad('не удалось подключиться к БД: ' . $e->getMessage());
  }
} else {
  info('пропущено — нет config.php');
}

echo "<h3>5. Версии ключевых файлов (проверка что залилась ПОСЛЕДНЯЯ версия, а не смесь старой и новой)</h3>";
$checkFiles = ['shorts.php', 'assets/css/style.css', 'includes/migrations.php', 'admin/update.php', 'comment_add.php', 'sql/migrations/001_add_comments.sql'];
foreach ($checkFiles as $f) {
  $full = __DIR__ . '/' . $f;
  if (file_exists($full)) {
    echo "<div>$f — " . filesize($full) . " байт, изменён " . date('Y-m-d H:i:s', filemtime($full)) . "</div>";
  } else {
    bad("$f отсутствует");
  }
}
$cssContent = @file_get_contents(__DIR__ . '/assets/css/style.css');
if ($cssContent !== false) {
  strpos($cssContent, '--accent-grad') !== false ? ok('style.css содержит актуальные CSS-переменные') : bad('style.css есть, но выглядит как СТАРАЯ версия без --accent-grad — перезалей assets/css/style.css');
}

echo "<h3>6. Сессии</h3>";
try {
  session_start();
  $_SESSION['diagnose_test'] = 1;
  ok('session_start() работает, session.save_path: ' . session_save_path());
} catch (\Throwable $e) {
  bad('проблема с сессиями: ' . $e->getMessage());
}

echo "<p style='color:#666;margin-top:30px'>Скопируй весь этот вывод и пришли — по нему сразу будет видно, в чём проблема.</p>";
echo "</body>";
