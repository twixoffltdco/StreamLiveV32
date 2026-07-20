<?php
// «Ты не робот?» + антидудос — своя защита без внешних сервисов (Google/Yandex captcha
// недоступны без ключей и не гарантированно работают на бесплатном хостинге).
// Два независимых уровня, оба полностью на своём коде — не требуют GD и вообще никаких
// внешних PHP-расширений:
//
//   Уровень 1 (новый): «под атакой» на весь сайт сразу — считаем ВСЕ гостевые запросы по
//   всему проекту в коротком скользящем окне. Если их резкий всплеск (например, распределённый
//   дудос со многих разных IP, каждый из которых по отдельности укладывается в персональный
//   лимит ниже) — временно требуем от КАЖДОГО гостя пройти лёгкую JS-проверку (страница вида
//   «проверяем ваш браузер…», ставит куку через JS и перезагружается), прежде чем запрос вообще
//   попадёт в обычную логику сайта. Простые дудос-скрипты на curl/requests без JS-движка её
//   не проходят — тот же принцип, что у Cloudflare "I'm Under Attack Mode".
//
//   Уровень 2 (как было): персональный рейт-лимит + SVG-капча на конкретный IP, теперь с
//   эскалацией — чем чаще этот IP уже попадался, тем дольше следующая блокировка.
//
// ВАЖНО: авторизованные пользователи (есть $_SESSION['user_id']) не проверяются вообще ни на
// одном из уровней — единственный сигнал доверия, который есть без внешнего сервиса, и именно
// так и должно быть: жёстко к анонимному/ботовому трафику, но не мешать уже вошедшим людям.

const ANTIBOT_LIMIT = 5;              // сколько просмотров страниц допускается конкретному IP
const ANTIBOT_WINDOW_SEC = 10;        // за какой промежуток времени (секунды)
const ANTIBOT_BLOCK_HOURS = 24;       // базовая блокировка при первом превышении
const ANTIBOT_MAX_BLOCK_HOURS = 720;  // потолок эскалации — 30 дней, дальше не растим
const ANTIBOT_CAPTCHA_PASS_HOURS = 24;  // после правильной капчи не спрашиваем её повторно сутки

const ANTIBOT_GLOBAL_WINDOW_SEC = 5;  // окно замера ОБЩЕГО гостевого трафика по всему сайту
const ANTIBOT_GLOBAL_LIMIT = 120;     // сколько гостевых запросов по сайту допускается за окно
const ANTIBOT_ATTACK_MODE_MIN = 10;   // на сколько минут включаем режим «под атакой» при превышении

// Пути, которые никогда не считаются и не блокируются ни на одном уровне — иначе сломается
// сам сайт (AJAX/поллинг вызывается каждые несколько секунд у любого активного зрителя,
// а сама капча/JS-проверка должны быть доступны тому, кого уже заблокировали).
function antibot_exempt_paths(): array {
  return [
    '/antibot_challenge.php', '/antibot_verify.php', '/antibot_image.svg.php',
    '/auth/login.php', '/auth/register.php', '/auth/2fa_setup.php', '/auth/2fa_verify.php', '/auth/logout.php',
    '/now_playing.php', '/chat_poll.php', '/chat_send.php', '/chat_delete.php', '/chat_ban.php',
    '/message_poll.php', '/message_send.php', '/user_search.php', '/check_stream_live.php',
    '/broadcast_post_poll.php', '/broadcast_post_send.php',
    '/rss_forum.php', '/rss_channels.php',
    '/channel_like.php', '/channel_favorite.php', '/short_like.php',
    '/comment_add.php', '/comment_delete.php', '/video_comment_add.php',
    '/broadcast_post_comments.php', '/broadcast_post_comment_add.php', '/broadcast_post_react.php',
    '/video_like.php', '/video_favorite.php', '/video_progress_get.php', '/video_progress_save.php',
    '/watch_room_poll.php', '/watch_room_action.php', '/watch_room_chat_poll.php', '/watch_room_chat_send.php',
    '/playlist_add.php', '/stickers_available.php',
    '/sitemap.xml.php', '/robots.txt.php',
  ];
}

// Сравнение с исключениями — БЕЗ учёта .php на конце. Раньше сравнивали точной строкой,
// а после перехода на красивые ссылки (.htaccess) реальный REQUEST_URI для большинства
// путей — уже БЕЗ .php (например, "/auth/login", а не "/auth/login.php"), пока список
// выше исторически писался с .php. Из-за этого точное сравнение никогда не совпадало,
// и исключения из списка выше по факту НЕ РАБОТАЛИ — ни для логина, ни для 2FA, ни для
// части AJAX-путей. Именно это било по входу/2FA: обычный вход — это несколько запросов
// подряд (открыть страницу, отправить пароль, открыть форму 2FA, отправить код), и без
// рабочего исключения это легко превышает лимит 5 запросов/10 сек и ловит капчу.
function antibot_is_exempt_path(string $path): bool {
  $normalized = rtrim(preg_replace('#\.php$#', '', $path), '/');
  foreach (antibot_exempt_paths() as $exempt) {
    if ($normalized === rtrim(preg_replace('#\.php$#', '', $exempt), '/')) return true;
  }
  return false;
}

function antibot_client_ip(): string {
  // На большинстве бесплатного шаред-хостинга сайт не за собственным прокси/CDN,
  // так что REMOTE_ADDR обычно надёжен. X-Forwarded-For берём только как запасной
  // вариант и на всякий случай валидируем формат, чтобы его нельзя было тривиально подделать
  // и накрутить блокировку постороннему IP.
  $candidate = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  if (empty($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if (filter_var($first, FILTER_VALIDATE_IP)) $candidate = $first;
  }
  return $candidate;
}

function antibot_ensure_table(): void {
  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS antibot_ip_log (
        ip VARCHAR(45) PRIMARY KEY,
        request_count INT NOT NULL DEFAULT 0,
        window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        blocked_until DATETIME DEFAULT NULL,
        block_count INT NOT NULL DEFAULT 0,
        captcha_code VARCHAR(10) DEFAULT NULL,
        captcha_expires DATETIME DEFAULT NULL,
        captcha_pass_until DATETIME DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
  } catch (\Throwable $e) { /* уже есть, или нет прав на CREATE — тогда просто пропустим защиту ниже */ }

  // block_count — колонка для эскалации, captcha_pass_until — запоминание успешно
  // пройденной капчи (см. antibot_guard). На проектах, где таблица уже была создана
  // раньше без них, добавляем отдельными ALTER. Пробуем максимум один раз за время
  // жизни приложения (флаг в settings), а не на каждый гостевой запрос — иначе лишняя
  // попытка ALTER на каждый хит будет зря грузить БД после первого же успеха/неудачи.
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    if (get_setting('antibot_schema_v3') === '1') return;
    try {
      db()->exec('ALTER TABLE antibot_ip_log ADD COLUMN block_count INT NOT NULL DEFAULT 0');
    } catch (\Throwable $e) { /* колонка уже есть — это нормально */ }
    try {
      db()->exec('ALTER TABLE antibot_ip_log ADD COLUMN captcha_pass_until DATETIME DEFAULT NULL');
    } catch (\Throwable $e) { /* колонка уже есть — это нормально */ }
    set_setting('antibot_schema_v3', '1');
  } catch (\Throwable $e) { /* settings недоступны — не критично, просто попробуем ALTER ещё раз в другой раз */ }
}

function antibot_ensure_global_table(): void {
  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS antibot_global_window (
        id TINYINT UNSIGNED PRIMARY KEY,
        window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        request_count INT NOT NULL DEFAULT 0,
        attack_until DATETIME DEFAULT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    db()->exec('INSERT IGNORE INTO antibot_global_window (id, window_start, request_count) VALUES (1, NOW(), 0)');
  } catch (\Throwable $e) { /* см. antibot_ensure_table — просто пропускаем защиту, если БД недоступна */ }
}

// Генерирует новый код капчи для этого IP и возвращает его (для рендера SVG).
function antibot_new_code(string $ip): string {
  $alphabet = 'АБВГДЕЖКМНПРСТУХ23456789'; // без похожих друг на друга символов
  // ВАЖНО: кириллица в UTF-8 — это 2 байта на букву. Обращение к строке через []
  // берёт БАЙТ, а не символ, и портит кодировку (MySQL потом отказывается сохранять
  // такую строку). Через mb_substr() берём именно символ.
  $alphabetLen = mb_strlen($alphabet);
  $code = '';
  for ($i = 0; $i < 5; $i++) $code .= mb_substr($alphabet, random_int(0, $alphabetLen - 1), 1);
  $expires = date('Y-m-d H:i:s', time() + 600); // 10 минут, считаем в PHP (см. antibot_guard про часовые пояса)
  db()->prepare('UPDATE antibot_ip_log SET captcha_code = ?, captcha_expires = ? WHERE ip = ?')
    ->execute([$code, $expires, $ip]);
  return $code;
}

// ---- Уровень 1: общесайтовый режим «под атакой» ----

// Считает гостевые запросы по всему сайту в скользящем окне; если лимит превышен — включает
// режим «под атакой» на ANTIBOT_ATTACK_MODE_MIN минут. Возвращает ['attack_mode' => bool].
function antibot_register_global_request(): array {
  $pdo = db();
  $nowTs = time();
  $stmt = $pdo->prepare('SELECT * FROM antibot_global_window WHERE id = 1');
  $stmt->execute();
  $row = $stmt->fetch();
  if (!$row) return ['attack_mode' => false]; // таблица не создалась (нет прав на БД) — просто не мешаем

  $attackMode = !empty($row['attack_until']) && strtotime($row['attack_until']) > $nowTs;

  $windowAge = $nowTs - strtotime($row['window_start']);
  if ($windowAge > ANTIBOT_GLOBAL_WINDOW_SEC) {
    $pdo->prepare('UPDATE antibot_global_window SET request_count = 1, window_start = ? WHERE id = 1')
      ->execute([date('Y-m-d H:i:s', $nowTs)]);
    return ['attack_mode' => $attackMode];
  }

  $newCount = (int)$row['request_count'] + 1;
  if ($newCount > ANTIBOT_GLOBAL_LIMIT && !$attackMode) {
    $until = date('Y-m-d H:i:s', $nowTs + ANTIBOT_ATTACK_MODE_MIN * 60);
    $pdo->prepare('UPDATE antibot_global_window SET request_count = ?, attack_until = ? WHERE id = 1')->execute([$newCount, $until]);
    return ['attack_mode' => true];
  }
  $pdo->prepare('UPDATE antibot_global_window SET request_count = ? WHERE id = 1')->execute([$newCount]);
  return ['attack_mode' => $attackMode];
}

// Секрет для подписи JS-пропуска — генерируется один раз сам и хранится в settings
// (тот же приём, что totp_token_secret() в includes/auth.php).
function antibot_js_secret(): string {
  $secret = get_setting('antibot_js_secret');
  if (!$secret) {
    $secret = bin2hex(random_bytes(32));
    set_setting('antibot_js_secret', $secret);
  }
  return $secret;
}

// Токен привязан к IP и часовому «бакету» — час достаточно долго, чтобы не гонять гостя
// через проверку на каждый чих, но и не даёт токену жить вечно после отключения attack mode.
function antibot_js_token(string $ip, ?int $hourBucket = null): string {
  $hourBucket = $hourBucket ?? (int)floor(time() / 3600);
  return hash_hmac('sha256', $ip . '|' . $hourBucket, antibot_js_secret());
}

function antibot_js_pass_valid(string $ip): bool {
  $cookie = $_COOKIE['sl_attack_pass'] ?? '';
  if ($cookie === '') return false;
  $currentHour = (int)floor(time() / 3600);
  // Проверяем текущий час и предыдущий — иначе кука, выданная за минуту до смены часа,
  // отваливалась бы ровно на границе, хотя запрос честный.
  return hash_equals(antibot_js_token($ip, $currentHour), $cookie)
      || hash_equals(antibot_js_token($ip, $currentHour - 1), $cookie);
}

function antibot_show_js_challenge(string $ip): void {
  http_response_code(503);
  header('Retry-After: 3');
  $returnTo = $_SERVER['REQUEST_URI'] ?? '/';
  $token = antibot_js_token($ip);
  require __DIR__ . '/../antibot_js_challenge_view.php';
  exit;
}


function antibot_captcha_pass_valid(?array $row): bool {
  return $row && !empty($row['captcha_pass_until']) && strtotime($row['captcha_pass_until']) > time();
}

function antibot_mark_captcha_passed(string $ip): void {
  $passUntil = date('Y-m-d H:i:s', time() + ANTIBOT_CAPTCHA_PASS_HOURS * 3600);
  db()->prepare('UPDATE antibot_ip_log SET blocked_until = NULL, request_count = 0, window_start = ?, captcha_code = NULL, captcha_expires = NULL, captcha_pass_until = ? WHERE ip = ?')
    ->execute([date('Y-m-d H:i:s'), $passUntil, $ip]);
}

// ---- Уровень 2: персональный рейт-лимит + SVG-капча (как было, плюс эскалация) ----

// Главная проверка — вызывается в самом начале обработки запроса (из functions.php).
//
// ВАЖНО: все временные метки здесь считаются и пишутся из PHP (date('Y-m-d H:i:s')),
// а не через MySQL NOW()/DATE_ADD. На бесплатном хостинге часовой пояс самого сервера
// MySQL нам не подконтролен и обычно UTC, а PHP здесь настроен на Europe/Moscow —
// если писать NOW() в базу, а потом сравнивать через PHP strtotime(), время разъедется
// на 3 часа и лимит запросов вообще никогда не сработает. Считаем всё только в PHP.
function antibot_guard(): void {
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  if (antibot_is_exempt_path((string)$path)) return;
  // Панель администратора не блокируем антиботом вообще — админ не должен случайно
  // сам себя запереть на 24 часа, кликая по своим же страницам.
  if (strpos((string)$path, '/admin/') === 0) return;

  // Авторизованные пользователи не проверяются вообще — ни глобальный JS-челлендж,
  // ни персональный рейт-лимит их не касаются. Это осознанное решение: жёстко для
  // анонимного трафика, но без лишних барьеров для уже вошедших людей.
  if (!empty($_SESSION['user_id']) || !empty($_SESSION['pending_2fa_user_id'])) return;

  try {
    antibot_ensure_table();
    antibot_ensure_global_table();
    $ip = antibot_client_ip();

    $globalState = antibot_register_global_request();
    if ($globalState['attack_mode'] && !antibot_js_pass_valid($ip)) {
      antibot_show_js_challenge($ip);
      return;
    }

    $pdo = db();
    $nowStr = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('SELECT * FROM antibot_ip_log WHERE ip = ?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch() ?: null;

    if (antibot_captcha_pass_valid($row)) {
      return;
    }

    if ($row && $row['blocked_until'] && strtotime($row['blocked_until']) > time()) {
      antibot_show_challenge($ip, false);
      return;
    }

    if (!$row) {
      $pdo->prepare('INSERT INTO antibot_ip_log (ip, request_count, window_start) VALUES (?, 1, ?)')->execute([$ip, $nowStr]);
      return;
    }

    $windowAge = time() - strtotime($row['window_start']);
    if ($windowAge > ANTIBOT_WINDOW_SEC) {
      $pdo->prepare('UPDATE antibot_ip_log SET request_count = 1, window_start = ? WHERE ip = ?')->execute([$nowStr, $ip]);
      return;
    }

    $newCount = (int)$row['request_count'] + 1;
    if ($newCount > ANTIBOT_LIMIT) {
      // Эскалация: чем чаще этот IP уже попадался, тем дольше следующая блокировка
      // (24ч → 48ч → 96ч → ... до потолка в 30 дней), вместо фиксированных 24 часов
      // на любое нарушение каждый раз — постоянно ломящийся бот со временем блокируется
      // надолго, а не разгадывает капчу заново каждые сутки.
      $blockCount = (int)($row['block_count'] ?? 0) + 1;
      $hours = min(ANTIBOT_BLOCK_HOURS * (2 ** ($blockCount - 1)), ANTIBOT_MAX_BLOCK_HOURS);
      $blockedUntil = date('Y-m-d H:i:s', time() + $hours * 3600);
      $pdo->prepare('UPDATE antibot_ip_log SET blocked_until = ?, block_count = ? WHERE ip = ?')->execute([$blockedUntil, $blockCount, $ip]);
      antibot_show_challenge($ip, true);
      return;
    }
    $pdo->prepare('UPDATE antibot_ip_log SET request_count = ? WHERE ip = ?')->execute([$newCount, $ip]);
  } catch (\Throwable $e) {
    // Если таблицы/БД временно недоступны — не роняем весь сайт из-за антибота,
    // просто пропускаем проверку на этот запрос.
    return;
  }
}

function antibot_show_challenge(string $ip, bool $justBlocked): void {
  http_response_code(429);
  $returnTo = $_SERVER['REQUEST_URI'] ?? '/';
  antibot_new_code($ip);
  require __DIR__ . '/../antibot_challenge_view.php';
  exit;
}
