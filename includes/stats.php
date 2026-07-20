<?php
// Статистика посещений — реальные цифры для админки и для заявок в рекламные сети
// (РСЯ, Google AdSense и др.), которым нужна прозрачная статистика трафика сайта.

function stats_ensure_table(): void {
  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS page_views (
        id INT AUTO_INCREMENT PRIMARY KEY,
        path VARCHAR(255) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        user_id INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        INDEX idx_path (path)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
  } catch (\Throwable $e) { /* уже есть, или нет прав — тогда просто не считаем в этот раз */ }

  // user_agent — добавлена позже (миграция 022), нужна модулю "кто сейчас на сайте", чтобы
  // отличать ботов (Googlebot, YandexBot и т.д.) от живых гостей. Пробуем ALTER максимум
  // один раз за время жизни приложения, а не на каждый пейджвью — так же, как в antibot.php.
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    if (get_setting('stats_schema_v2') === '1') return;
    try {
      db()->exec('ALTER TABLE page_views ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL');
    } catch (\Throwable $e) { /* колонка уже есть — это нормально */ }
    set_setting('stats_schema_v2', '1');
  } catch (\Throwable $e) { /* settings недоступны — не критично, попробуем ещё раз в другой раз */ }
}

// Пути, которые не считаются просмотром страницы — AJAX/поллинг/технические, иначе
// счётчик будет врать (один активный зритель чата даст сотни "просмотров" в час).
function stats_exempt_paths(): array {
  return array_merge(antibot_exempt_paths(), [
    '/sitemap.xml.php', '/robots.txt', '/diagnose.php',
  ]);
}

function track_pageview(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  if (in_array($path, stats_exempt_paths(), true)) return;
  if (strpos((string)$path, '/admin/') === 0) return; // свои же визиты в админку не засоряют статистику посетителей

  try {
    stats_ensure_table();
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ip = antibot_client_ip();
    $userId = $_SESSION['user_id'] ?? null;
    $path = mb_substr((string)$path, 0, 255);
    try {
      db()->prepare('INSERT INTO page_views (path, ip, user_id, user_agent) VALUES (?, ?, ?, ?)')
        ->execute([$path, $ip, $userId, $ua]);
    } catch (\Throwable $e) {
      // Колонка user_agent ещё не создалась (миграция/ALTER не применились) — не теряем
      // весь просмотр целиком, пишем хотя бы старыми тремя полями.
      db()->prepare('INSERT INTO page_views (path, ip, user_id) VALUES (?, ?, ?)')
        ->execute([$path, $ip, $userId]);
    }
  } catch (\Throwable $e) { /* не критично — пропускаем этот просмотр, не роняем страницу */ }
}

// ---- Агрегированные цифры для дашбордов (админский и публичный) ----

function stats_summary(int $days = 30): array {
  stats_ensure_table();
  $pdo = db();
  $since = date('Y-m-d H:i:s', time() - $days * 86400);

  $totalViews = (int)$pdo->query('SELECT COUNT(*) FROM page_views')->fetchColumn();

  $stmt = $pdo->prepare('SELECT COUNT(*) FROM page_views WHERE created_at >= ?');
  $stmt->execute([$since]);
  $recentViews = (int)$stmt->fetchColumn();

  $stmt = $pdo->prepare('SELECT COUNT(DISTINCT ip) FROM page_views WHERE created_at >= ?');
  $stmt->execute([$since]);
  $uniqueVisitors = (int)$stmt->fetchColumn();

  $todaySince = date('Y-m-d 00:00:00');
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM page_views WHERE created_at >= ?');
  $stmt->execute([$todaySince]);
  $todayViews = (int)$stmt->fetchColumn();

  // Просмотры по дням за период — для графика
  $stmt = $pdo->prepare(
    'SELECT DATE(created_at) AS d, COUNT(*) AS c, COUNT(DISTINCT ip) AS u
     FROM page_views WHERE created_at >= ? GROUP BY DATE(created_at) ORDER BY d ASC'
  );
  $stmt->execute([$since]);
  $byDay = $stmt->fetchAll();

  // Самые популярные страницы за период
  $stmt = $pdo->prepare(
    'SELECT path, COUNT(*) AS c FROM page_views WHERE created_at >= ? GROUP BY path ORDER BY c DESC LIMIT 15'
  );
  $stmt->execute([$since]);
  $topPaths = $stmt->fetchAll();

  return [
    'total_views' => $totalViews,
    'recent_views' => $recentViews,
    'unique_visitors' => $uniqueVisitors,
    'today_views' => $todayViews,
    'avg_daily' => $days > 0 ? round($recentViews / $days, 1) : 0,
    'by_day' => $byDay,
    'top_paths' => $topPaths,
  ];
}

// ---- "Кто сейчас на сайте" (как в XenForo) — переиспользует page_views, которая и так
// пишется на каждый просмотр (см. track_pageview() выше), отдельной таблицы не заводим. ----

const ONLINE_WINDOW_SEC = 300; // 5 минут — активным считается тот, у кого был просмотр за это время

function online_bot_name(string $userAgent): ?string {
  if ($userAgent === '') return null;
  $bots = [
    'Googlebot' => 'googlebot', 'YandexBot' => 'yandexbot', 'Bingbot' => 'bingbot',
    'DuckDuckBot' => 'duckduckbot', 'Baiduspider' => 'baiduspider', 'AhrefsBot' => 'ahrefsbot',
    'SemrushBot' => 'semrushbot', 'MJ12bot' => 'mj12bot', 'Bytespider' => 'bytespider',
    'PetalBot' => 'petalbot', 'GPTBot' => 'gptbot', 'ClaudeBot' => 'claudebot',
    'facebookexternalhit' => 'facebookexternalhit', 'TelegramBot' => 'telegrambot',
    'Applebot' => 'applebot', 'DotBot' => 'dotbot', 'Mail.Ru' => 'mail.ru',
    'SputnikBot' => 'sputnikbot', 'proximic' => 'proximic', 'curl' => 'curl/', 'python-requests' => 'python-requests',
  ];
  foreach ($bots as $label => $needle) {
    if (stripos($userAgent, $needle) !== false) return $label;
  }
  return null;
}

// Грубое, но достаточное сопоставление URL → человеко-читаемая подпись для списка "кто что смотрит".
function online_path_label(string $path): string {
  $exact = [
    '/' => 'Главная', '/videos' => 'Видео', '/catalog' => 'Каталог каналов',
    '/forum' => 'Форум', '/rating' => 'Рейтинг', '/services' => 'Конструктор сервисов',
    '/messages' => 'Сообщения', '/search' => 'Поиск', '/dashboard' => 'Личный кабинет',
    '/shorts' => 'Короткие видео', '/playlists' => 'Плейлисты', '/news' => 'Новости',
    '/resources' => 'Каталог ресурсов',
  ];
  if (isset($exact[$path])) return $exact[$path];
  $prefixes = [
    '/video' => 'Смотрит видео', '/channel' => 'Смотрит канал',
    '/forum_thread' => 'Читает тему форума', '/forum_category' => 'Раздел форума',
    '/broadcast_channel' => 'Читает канал в мессенджере', '/profile' => 'Смотрит профиль',
    '/watch_room' => 'В комнате совместного просмотра', '/resource' => 'Смотрит ресурс', '/u/' => 'Смотрит профиль',
  ];
  foreach ($prefixes as $prefix => $label) {
    if (strpos($path, $prefix) === 0) return $label;
  }
  return $path;
}

function mask_ip(string $ip): string {
  $parts = explode('.', $ip);
  if (count($parts) === 4) { $parts[3] = 'x'; return implode('.', $parts); }
  return substr($ip, 0, (int)(strlen($ip) / 2)) . '…'; // грубая маскировка для IPv6
}

// Возвращает снимок «кто сейчас на сайте»: залогиненные пользователи по отдельности,
// гости и роботы сгруппированы по IP+User-Agent (сессий у гостей нет, это лучшее приближение).
function online_snapshot(): array {
  $pdo = db();
  $since = date('Y-m-d H:i:s', time() - ONLINE_WINDOW_SEC);

  try {
    $stmt = $pdo->prepare(
      "SELECT pv.user_id, pv.path, pv.created_at, u.username
       FROM page_views pv JOIN users u ON u.id = pv.user_id
       INNER JOIN (
         SELECT user_id, MAX(id) AS max_id FROM page_views
         WHERE created_at >= ? AND user_id IS NOT NULL GROUP BY user_id
       ) latest ON latest.max_id = pv.id
       ORDER BY pv.created_at DESC"
    );
    $stmt->execute([$since]);
    $users = $stmt->fetchAll();
  } catch (\Throwable $e) { $users = []; }

  $guests = [];
  $bots = [];
  try {
    $stmt = $pdo->prepare(
      "SELECT pv.ip, pv.user_agent, pv.path, pv.created_at
       FROM page_views pv
       INNER JOIN (
         SELECT ip, user_agent, MAX(id) AS max_id FROM page_views
         WHERE created_at >= ? AND user_id IS NULL GROUP BY ip, user_agent
       ) latest ON latest.max_id = pv.id
       ORDER BY pv.created_at DESC LIMIT 300"
    );
    $stmt->execute([$since]);
    foreach ($stmt->fetchAll() as $row) {
      $botName = online_bot_name((string)($row['user_agent'] ?? ''));
      $item = ['ip' => $row['ip'], 'path' => $row['path'], 'seen_at' => $row['created_at'], 'user_agent' => $row['user_agent']];
      if ($botName) { $item['name'] = $botName; $bots[] = $item; } else { $guests[] = $item; }
    }
  } catch (\Throwable $e) { /* колонка user_agent ещё не создалась — просто не различаем ботов в этот раз */ }

  return ['users' => $users, 'guests' => $guests, 'bots' => $bots];
}
