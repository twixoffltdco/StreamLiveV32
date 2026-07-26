<?php
// Всё расписание эфиров и все временные отметки на сайте — по московскому времени (МСК, UTC+3),
// независимо от таймзоны, выставленной на самом сервере/хостинге.
date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
  // Явные параметры куки сессии: без этого на некоторых мобильных браузерах
  // (особенно после OAuth-редиректов) кука сессии может не сохраняться или
  // не отправляться обратно, из-за чего "слетает" 2FA/вход.
  $__isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  // 30 дней — раньше lifetime было 0 (кука "до закрытия браузера"), из-за чего вход слетал
  // при закрытии браузера/выключении ПК. Плюс поднимаем gc_maxlifetime — иначе даже с
  // долгоживущей курой хостинг мог сам стереть данные сессии на сервере по дефолтному
  // таймауту (часто ~24 минуты бездействия), и долгая кука была бы бесполезна.
  $__sessionLifetime = 60 * 60 * 24 * 30;
  ini_set('session.gc_maxlifetime', (string)$__sessionLifetime);
  session_set_cookie_params([
    'lifetime' => $__sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => $__isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

function e(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
  header('Location: ' . $path);
  exit;
}

function flash_set(string $type, string $message): void {
  $_SESSION['flash'][$type] = $message;
}

function flash_get(): array {
  $flash = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return $flash;
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_field(): string {
  return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void {
  $token = $_POST['csrf_token'] ?? '';
  if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    die('Неверный CSRF токен. Обновите страницу и попробуйте снова.');
  }
}

function slugify(string $title): string {
  $translit = mb_strtolower(trim($title));
  $translit = preg_replace('/[^a-zа-я0-9\s-]/iu', '', $translit);
  $translit = preg_replace('/\s+/', '-', $translit);
  return ($translit ?: 'channel') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
}

// Определяет активный источник вещания по недельному расписанию (по МСК), либо источник по умолчанию
function resolve_active_source(array $channel): ?array {
  // Владелец вручную остановил трансляцию ("Стоп трансляция" в настройках канала) —
  // не показываем источник вообще, независимо от расписания.
  if (!empty($channel['is_broadcast_paused'])) return null;

  $pdo = db();
  $now = new DateTime(); // уже в Europe/Moscow благодаря date_default_timezone_set()
  $dow = (int)$now->format('N') - 1; // 0=Пн..6=Вс
  $prevDow = ($dow + 6) % 7;
  $time = $now->format('H:i:s');
  // ВАЖНО: раньше передачи, которые переходят через полночь (например, 23:00–01:00),
  // никогда не подхватывались — простое "start_time <= time < end_time" не работает,
  // если end_time физически меньше start_time. Теперь учитываем оба случая: обычный
  // (в рамках одного дня) и ночной (начался вчера/сегодня и идёт после полуночи).
  //
  // ВАЖНО-2: именованный параметр (:dow и т.п.), повторённый в запросе дважды, при
  // "настоящих" (не эмулированных) prepared statements в MySQL/PDO не поддерживается
  // и валит запрос фатальной ошибкой на КАЖДОЙ загрузке страницы канала (HTTP 500).
  // Поэтому здесь используются позиционные "?" с повторением значений в массиве —
  // это всегда безопасно, независимо от настроек PDO::ATTR_EMULATE_PREPARES.
  $stmt = $pdo->prepare(
    'SELECT s.*, sch.id AS schedule_id, sch.program_title FROM schedule sch
     JOIN sources s ON s.id = sch.source_id
     WHERE sch.channel_id = ? AND (
       (sch.day_of_week = ? AND sch.end_time > sch.start_time AND sch.start_time <= ? AND sch.end_time > ?)
       OR (sch.day_of_week = ? AND sch.end_time <= sch.start_time AND sch.start_time <= ?)
       OR (sch.day_of_week = ? AND sch.end_time <= sch.start_time AND sch.end_time > ?)
     )
     ORDER BY sch.start_time DESC LIMIT 1'
  );
  $stmt->execute([$channel['id'], $dow, $time, $time, $dow, $time, $prevDow, $time]);
  $row = $stmt->fetch();
  if ($row) return $row;

  if (!empty($channel['default_source_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM sources WHERE id = ?');
    $stmt->execute([$channel['default_source_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
  }
  return null;
}

// Ближайшая следующая передача в расписании (для блока "Далее в эфире"), время — МСК
function find_next_program(int $channelId): ?array {
  $pdo = db();
  $now = new DateTime();
  $dow = (int)$now->format('N') - 1;
  $time = $now->format('H:i:s');

  $stmt = $pdo->prepare(
    'SELECT sch.*, s.name AS source_name FROM schedule sch
     JOIN sources s ON s.id = sch.source_id
     WHERE sch.channel_id = ? AND sch.day_of_week = ? AND sch.start_time > ?
     ORDER BY sch.start_time ASC LIMIT 1'
  );
  $stmt->execute([$channelId, $dow, $time]);
  $row = $stmt->fetch();
  if ($row) return $row;

  for ($i = 1; $i <= 7; $i++) {
    $nextDow = ($dow + $i) % 7;
    $stmt = $pdo->prepare(
      'SELECT sch.*, s.name AS source_name FROM schedule sch
       JOIN sources s ON s.id = sch.source_id
       WHERE sch.channel_id = ? AND sch.day_of_week = ?
       ORDER BY sch.start_time ASC LIMIT 1'
    );
    $stmt->execute([$channelId, $nextDow]);
    $row = $stmt->fetch();
    if ($row) return $row;
  }
  return null;
}

// Личные сообщения: находит существующий диалог между двумя пользователями либо создаёт новый.
// Пара всегда хранится в порядке user_a_id < user_b_id — чтобы не плодить дубли строк
// в двух направлениях для одной и той же пары людей.
function get_or_create_conversation(int $userIdA, int $userIdB): int {
  $a = min($userIdA, $userIdB);
  $b = max($userIdA, $userIdB);
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id FROM conversations WHERE user_a_id = ? AND user_b_id = ?');
  $stmt->execute([$a, $b]);
  $id = $stmt->fetchColumn();
  if ($id) return (int)$id;
  $pdo->prepare('INSERT INTO conversations (user_a_id, user_b_id) VALUES (?, ?)')->execute([$a, $b]);
  return (int)$pdo->lastInsertId();
}

// Автомодерация: если канал изначально аккуратно оформлен (нормальное название,
// осмысленное описание, логотип, и главное — рабочий источник вещания с валидной
// embed-ссылкой), одобряем сразу, без ожидания ручной проверки админом.
// Если чего-то не хватает — как раньше, уходит на модерацию вручную.
function channel_looks_complete(array $channel, ?array $source): bool {
  $title = trim((string)($channel['title'] ?? ''));
  $description = trim((string)($channel['description'] ?? ''));
  $logo = trim((string)($channel['logo_url'] ?? ''));

  if (mb_strlen($title) < 3) return false;
  if (mb_strlen($description) < 20) return false;
  if ($logo === '' || !filter_var($logo, FILTER_VALIDATE_URL)) return false;
  if (!$source) return false;

  $url = trim((string)($source['url'] ?? ''));
  if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return false;
  if (stripos($url, 'https://') !== 0) return false; // http без шифрования — тоже не пропускаем как "готовый"

  // Ссылка должна реально быть embed-форматом для своего типа, а не просто "любой валидный URL" —
  // иначе можно вставить страницу вида youtube.com/watch?v=... которая не откроется в iframe.
  switch ($source['type'] ?? '') {
    case 'youtube': if (strpos($url, 'youtube.com/embed/') === false) return false; break;
    case 'vk':      if (strpos($url, 'video_ext.php') === false) return false; break;
    case 'rutube':  if (strpos($url, '/play/embed/') === false) return false; break;
    case 'm3u8':    if (stripos($url, '.m3u8') === false) return false; break;
    case 'mp4':     if (stripos($url, '.mp4') === false && stripos($url, '.mov') === false && stripos($url, '.webm') === false) return false; break;
    case 'iframe':  break; // произвольный iframe — валидного https URL уже достаточно
    default: return false;
  }
  return true;
}

// Пробует автоматически одобрить канал, если он полностью и аккуратно оформлен.
// Понижать уже одобренный канал обратно в pending эта функция никогда не может — только повышает.
function maybe_auto_approve_channel(int $channelId): void {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT * FROM channels WHERE id = ?');
  $stmt->execute([$channelId]);
  $channel = $stmt->fetch();
  if (!$channel || $channel['status'] !== 'pending') return;

  $source = null;
  if (!empty($channel['default_source_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM sources WHERE id = ?');
    $stmt->execute([$channel['default_source_id']]);
    $source = $stmt->fetch() ?: null;
  }

  if (channel_looks_complete($channel, $source)) {
    $pdo->prepare("UPDATE channels SET status = 'approved' WHERE id = ? AND status = 'pending'")->execute([$channelId]);
  }
}

// Секрет для шифрования ключей трансляции (RTMP stream key) — генерируется один раз
// и хранится в settings, по той же схеме, что и totp_token_secret.
function secret_encryption_key(): string {
  $key = get_setting('secret_enc_key');
  if (!$key) {
    $key = bin2hex(random_bytes(32));
    set_setting('secret_enc_key', $key);
  }
  return $key;
}

// Шифрует произвольный секрет (например, RTMP stream key) для хранения в БД.
// Возвращает [зашифрованный_текст_base64, iv_base64] — оба значения нужно сохранить.
function encrypt_secret(string $plain): array {
  $key = hex2bin(secret_encryption_key());
  $iv = random_bytes(16);
  $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
  return [base64_encode($cipher), base64_encode($iv)];
}

// Расшифровывает секрет, сохранённый через encrypt_secret(). Возвращает null при ошибке
// (например, если данные повреждены или секрет шифрования сменился).
function decrypt_secret(?string $encB64, ?string $ivB64): ?string {
  if (!$encB64 || !$ivB64) return null;
  $key = hex2bin(secret_encryption_key());
  $plain = openssl_decrypt(base64_decode($encB64), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, base64_decode($ivB64));
  return $plain !== false ? $plain : null;
}

// Чтение/запись настроек сайта (ключ-значение) — используется, например, для API-ключа ИИ T2000
function get_setting(string $key, ?string $default = null): ?string {
  static $cache = [];
  if (array_key_exists($key, $cache)) return $cache[$key];
  $stmt = db()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
  $stmt->execute([$key]);
  $val = $stmt->fetchColumn();
  return $cache[$key] = ($val !== false ? $val : $default);
}

function set_setting(string $key, string $value): void {
  db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
    ->execute([$key, $value]);
}

// Лимит ИИ T2000: 2 запроса на аккаунт, затем перерыв 6 часов (окно катится, за сутки получается несколько заходов)
function check_ai_rate_limit(int $userId): array {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT * FROM ai_usage WHERE user_id = ?');
  $stmt->execute([$userId]);
  $row = $stmt->fetch();

  $now = time();
  $windowSeconds = 6 * 3600;

  if (!$row || !$row['window_started_at'] || ($now - strtotime($row['window_started_at'])) >= $windowSeconds) {
    $pdo->prepare(
      'INSERT INTO ai_usage (user_id, request_count, window_started_at) VALUES (?, 1, NOW())
       ON DUPLICATE KEY UPDATE request_count = 1, window_started_at = NOW()'
    )->execute([$userId]);
    return ['allowed' => true];
  }

  if ((int)$row['request_count'] < 2) {
    $pdo->prepare('UPDATE ai_usage SET request_count = request_count + 1 WHERE user_id = ?')->execute([$userId]);
    return ['allowed' => true];
  }

  $retryAfter = $windowSeconds - ($now - strtotime($row['window_started_at']));
  return ['allowed' => false, 'retry_after' => max(0, $retryAfter)];
}

// Проверка бана пользователя на канале (полный бан: и чат, и просмотр самой страницы канала)
function is_user_banned_on_channel(int $channelId, int $userId): bool {
  $stmt = db()->prepare('SELECT id FROM chat_bans WHERE channel_id = ? AND user_id = ?');
  $stmt->execute([$channelId, $userId]);
  return (bool)$stmt->fetch();
}

// Рендер текста (комментарий/чат) с заменой кодов стикеров вида :code: на <img>.
// Проверяет и локальные стикеры канала (если переданы), и ГЛОБАЛЬНЫЕ стикеры из
// пользовательских стикер-паков (как в Telegram) — они работают везде одинаково.
function render_with_stickers(string $message, array $stickers = []): string {
  $html = nl2br(e($message));

  // Собираем все возможные коды одним проходом: сначала переданные (канал), затем
  // глобальные из стикер-паков — но только те, что реально встречаются в тексте
  // (ищем по сырому тексту сообщения, а не по каждому стикеру в базе, чтобы не
  // гонять лишний SELECT на каждый комментарий без единого стикера).
  if (preg_match_all('/:[a-z0-9_]+:/i', $message, $m) && !empty($m[0])) {
    $codesInText = array_unique($m[0]);
    try {
      $in = implode(',', array_fill(0, count($codesInText), '?'));
      $stmt = db()->prepare("SELECT code, image_url FROM sticker_pack_items WHERE code IN ($in)");
      $stmt->execute($codesInText);
      foreach ($stmt->fetchAll() as $row) $stickers[] = $row;
    } catch (\Throwable $e) { /* таблица ещё не создана — просто пропускаем глобальные паки */ }
  }

  foreach ($stickers as $s) {
    $code = trim((string)($s['code'] ?? ''));
    if ($code === '') continue;
    $escapedCode = e($code);
    if (mb_strpos($html, $escapedCode) === false) continue;
    $img = '<img src="' . e($s['image_url']) . '" alt="' . $escapedCode . '" class="inline-sticker" style="width:22px;height:22px;vertical-align:middle;object-fit:contain;display:inline-block">';
    $html = str_replace($escapedCode, $img, $html);
  }
  return $html;
}

// Проверка номера телефона: без SMS-подтверждения (по требованию), но формат должен
// быть похож на настоящий международный номер, а не мусор вроде "12345".
// Возвращает нормализованный номер (+79991234567) либо null, если формат не похож на реальный.
function normalize_phone(string $raw): ?string {
  $digits = preg_replace('/[^\d+]/', '', $raw);
  $digits = preg_replace('/(?!^)\+/', '', $digits); // + разрешён только в начале
  if (!preg_match('/^\+?[1-9]\d{9,14}$/', $digits)) return null;
  if ($digits[0] !== '+') $digits = '+' . $digits;
  return $digits;
}


function table_column_exists(string $table, string $column): bool {
  $stmt = db()->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->execute([$table, $column]);
  return (bool)$stmt->fetchColumn();
}

// Место в общем рейтинге по XP (та же метрика, что и в rating.php). Используется для баннера
// "твоё место в рейтинге" в профиле и мессенджере.
function user_rank(int $userId): ?int {
  $stmt = db()->prepare('SELECT xp FROM users WHERE id = ?');
  $stmt->execute([$userId]);
  $xp = $stmt->fetchColumn();
  if ($xp === false) return null;
  $stmt2 = db()->prepare('SELECT COUNT(*) + 1 FROM users WHERE xp > ? AND is_banned = 0');
  $stmt2->execute([$xp]);
  return (int)$stmt2->fetchColumn();
}

function rating_place_banner(int $userId): string {
  try {
    $place = user_rank($userId);
  } catch (\Throwable $e) { return ''; }
  if (!$place) return '';
  return '<div class="rating-place-banner" style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,rgba(255,193,7,.18),rgba(255,152,0,.10));border:1px solid rgba(255,193,7,.4);border-radius:10px;padding:6px 12px;font-size:13px;margin:8px 0">'
    . '🏆 Ваше место в рейтинге: <b><a href="/rating" style="color:inherit">#' . (int)$place . '</a></b></div>';
}

function user_avatar_url(array $user, int $size = 96): string {
  $email = trim((string)($user['gravatar_email'] ?? ''));
  if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return 'https://www.gravatar.com/avatar/' . md5(strtolower($email)) . '?s=' . max(24, min(512, $size)) . '&d=mp';
  }
  return $user['avatar'] ?: '/assets/img/avatar-placeholder.png';
}

// Единый компонент "аватарка + ник" — как на YouTube/TikTok, чтобы везде на сайте
// (форум, каналы-рассылки, комментарии, сообщения, ресурсы) выглядело одинаково,
// а не по-разному в каждом шаблоне. $size — диаметр кружка в пикселях.
function render_user_badge(array $user, int $size = 28, bool $link = true): string {
  $avatar = user_avatar_url($user, $size * 2); // берём с запасом на retina-экраны
  $name = e($user['username'] ?? 'Гость');
  $verified = !empty($user['is_verified']) ? verify_badge(true) : '';
  $img = '<img src="' . e($avatar) . '" alt="" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;flex-shrink:0' . (!empty($user['is_banned']) ? ';filter:grayscale(1);opacity:.6' : '') . '" loading="lazy">';
  $inner = $img . '<span style="font-weight:600">' . $name . '</span>' . $verified;
  $style = 'display:inline-flex;align-items:center;gap:8px;text-decoration:none;color:inherit';
  $row = $link && !empty($user['username'])
    ? '<a href="/profile?username=' . urlencode($user['username']) . '" style="' . $style . '">' . $inner . '</a>'
    : '<span style="' . $style . '">' . $inner . '</span>';

  if (empty($user['is_banned'])) return $row;

  // Настоящий заметный баннер, а не мелкая подпись — раньше здесь была едва заметная пилюля
  // рядом с ником, которую легко не увидеть. Теперь везде (форум, комментарии видео, посты
  // каналов-рассылок) одинаково явно, как уже было в профиле и в личных сообщениях.
  return '<div>' . $row .
    '<div class="banned-user-notice" style="margin-top:4px;display:flex;align-items:center;gap:6px;background:rgba(255,71,87,0.12);border:1px solid var(--danger);border-radius:8px;padding:4px 10px;font-size:12px;color:var(--danger)">' .
    '⛔ Этот аккаунт заблокирован на платформе. Мы не несём ответственности за действия пользователя вне платформы.' .
    '</div></div>';
}

function ensure_user_gravatar_column(): void {
  if (!table_column_exists('users', 'gravatar_email')) {
    db()->exec('ALTER TABLE users ADD COLUMN gravatar_email VARCHAR(191) DEFAULT NULL');
  }
}

// То же самое, что ensure_user_gravatar_column(), но для ТВ/радио каналов — Gravatar и
// обложка/баннер канала (sql/migrations/027_channel_gravatar_cover.sql). Пробуем максимум
// раз за время жизни процесса — не гонять ALTER на каждый заход на страницу канала.
function ensure_sources_direct_type(): void {
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    if (get_setting('sources_direct_type_v1') === '1') return;
    db()->exec("ALTER TABLE sources MODIFY COLUMN type ENUM('mp4','m3u8','youtube','vk','rutube','iframe','direct') NOT NULL");
    set_setting('sources_direct_type_v1', '1');
  } catch (\Throwable $e) { /* нет прав ALTER — залей sql/migrations/031_sources_direct_type.sql руками через phpMyAdmin */ }
}

// То же самое, что ensure_user_gravatar_column(), но для ТВ/радио каналов — Gravatar и
// обложка/баннер канала (sql/migrations/027_channel_gravatar_cover.sql). Пробуем максимум
// раз за время жизни процесса — не гонять ALTER на каждый заход на страницу канала.
function ensure_channel_avatar_columns(): void {
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    if (!table_column_exists('channels', 'gravatar_email')) {
      db()->exec('ALTER TABLE channels ADD COLUMN gravatar_email VARCHAR(191) DEFAULT NULL');
    }
    if (!table_column_exists('channels', 'cover_url')) {
      db()->exec('ALTER TABLE channels ADD COLUMN cover_url VARCHAR(500) DEFAULT NULL');
    }
  } catch (Throwable $e) { /* нет прав ALTER на хостинге — тогда просто выполни миграцию 027 руками через phpMyAdmin */ }
}

// Логотип ТВ/радио канала — тот же принцип, что у user_avatar_url(): если владелец указал
// gravatar_email — тянем аватар с Gravatar по e-mail, иначе — logo_url (прямая ссылка),
// иначе — заглушка.
function channel_avatar_url(array $channel, int $size = 96): string {
  $email = trim((string)($channel['gravatar_email'] ?? ''));
  if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return 'https://www.gravatar.com/avatar/' . md5(strtolower($email)) . '?s=' . max(24, min(512, $size)) . '&d=mp';
  }
  return $channel['logo_url'] ?: '/assets/img/avatar-placeholder.png';
}

function recommended_channels(?array $user, ?int $currentId = null, int $limit = 20): array {
  $viewed = array_filter(array_map('intval', explode(',', (string)($_COOKIE['viewed_channels'] ?? ''))));
  $sql = "SELECT c.* FROM channels c WHERE c.status = 'approved'";
  $params = [];
  if ($currentId) { $sql .= ' AND c.id != ?'; $params[] = $currentId; }
  $stmt = db()->prepare($sql); $stmt->execute($params); $channels = $stmt->fetchAll();
  $scores = [];
  foreach ($channels as $c) {
    $id = (int)$c['id'];
    $scores[$id] = log(1 + (int)($c['views'] ?? 0)) + (strtotime($c['created_at'] ?? 'now') / 86400 / 3650);
    foreach (preg_split('/\W+/u', mb_strtolower(($c['title'] ?? '') . ' ' . ($c['description'] ?? ''))) as $w) {
      if ($w !== '' && isset($_COOKIE['interest_' . md5($w)])) $scores[$id] += 3;
    }
    if (in_array($id, $viewed, true)) $scores[$id] -= 5;
  }
  if ($user) {
    try {
      $stmt = db()->prepare('SELECT channel_id FROM favorites WHERE user_id = ? UNION SELECT channel_id FROM channel_likes WHERE user_id = ?');
      $stmt->execute([$user['id'], $user['id']]);
      $liked = $stmt->fetchAll(PDO::FETCH_COLUMN);
      foreach ($liked as $cid) $scores[(int)$cid] = ($scores[(int)$cid] ?? 0) + 20;
    } catch (Throwable $e) {}
  }
  usort($channels, fn($a,$b) => ($scores[(int)$b['id']] ?? 0) <=> ($scores[(int)$a['id']] ?? 0));
  return array_slice($channels, 0, max(1, min(100, $limit)));
}

// Значок верификации (галочка как в Telegram) рядом с ником — выдаётся вручную в админке.
function verify_badge(bool $isVerified): string {
  return $isVerified ? ' <span class="verify-badge" title="Подтверждённый аккаунт"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.4 16.7 4.9 12.2l1.8-1.8 2.7 2.7 7.9-7.9 1.8 1.8z"/></svg></span>' : '';
}

// Список известных краулеров/ИИ-агентов — используется антидудосом/антиботом ниже (чтобы не
// показывать им JS-проверку/капчу/страницу перегрузки, которую они физически не могут пройти),
// и модулем "кто сейчас на сайте". Объявлено здесь, а не в stats.php, потому что stats.php
// подключается значительно позже antibot.php/ddos_shield.php — этой функции нужна уже на
// первом запросе.
//
// ВАЖНО: DeepSeek принципиально НЕ публикует свой User-Agent — его запросы неотличимы от
// обычного браузера в логах. Список ниже НЕ решает видимость для DeepSeek по самому User-Agent
// — для него единственный надёжный способ не блокировать — не показывать ни JS-проверку,
// ни "занято" ЛЮБОМУ читающему GET-запросу без крайней необходимости (см. ddos_concurrency_guard()
// и ddos_under_attack_mode_enabled() ниже).
function crawler_ua_map(): array {
  return [
    'Googlebot' => 'googlebot', 'YandexBot' => 'yandexbot', 'Bingbot' => 'bingbot',
    'DuckDuckBot' => 'duckduckbot', 'Baiduspider' => 'baiduspider', 'AhrefsBot' => 'ahrefsbot',
    'SemrushBot' => 'semrushbot', 'MJ12bot' => 'mj12bot', 'Bytespider' => 'bytespider',
    'PetalBot' => 'petalbot', 'Applebot' => 'applebot', 'DotBot' => 'dotbot', 'Mail.Ru' => 'mail.ru',
    'SputnikBot' => 'sputnikbot', 'proximic' => 'proximic',
    'GPTBot' => 'GPTBot', 'ChatGPT-User' => 'ChatGPT-User', 'OAI-SearchBot' => 'OAI-SearchBot',
    'ClaudeBot' => 'ClaudeBot', 'Claude-User' => 'Claude-User', 'Claude-SearchBot' => 'Claude-SearchBot',
    'PerplexityBot' => 'PerplexityBot', 'Perplexity-User' => 'Perplexity-User',
    'MistralAI-User' => 'MistralAI-User', 'Google-Extended' => 'Google-Extended',
    'Applebot-Extended' => 'Applebot-Extended', 'Meta-ExternalAgent' => 'Meta-ExternalAgent',
    'facebookexternalhit' => 'facebookexternalhit', 'TelegramBot' => 'TelegramBot',
    'YouBot' => 'YouBot', 'Amazonbot' => 'Amazonbot',
    'curl' => 'curl/', 'python-requests' => 'python-requests', 'Wget' => 'Wget/',
  ];
}

function is_known_crawler_ua(string $userAgent): bool {
  if ($userAgent === '') return false;
  foreach (crawler_ua_map() as $needle) {
    if (stripos($userAgent, $needle) !== false) return true;
  }
  return false;
}

// Второй слой защиты — от перегрузки БД при всплеске одновременных запросов и от
// злоупотребления дорогими операциями (импорт видео, деплой). Стоит ПЕРЕД антиботом
// специально: если сайт уже перегружен, даже запрос антибота к БД может быть лишним.
require_once __DIR__ . '/antibot.php'; // только объявления функций (antibot_client_ip нужна ddos_shield.php), сам antibot_guard() вызовем ниже
require_once __DIR__ . '/ddos_shield.php';
ddos_guard();

// «Ты не робот?» — антибот-проверка на каждый запрос (пропускает AJAX/поллинг и саму
// капчу, см. includes/antibot.php).
antibot_guard();

// Гео-ограничение (только страны СНГ) — по умолчанию выключено, включается в админке.
require_once __DIR__ . '/geo.php';
geo_guard();

// Статистика посещений — для админки и заявок в рекламные сети.
require_once __DIR__ . '/stats.php';
track_pageview();
