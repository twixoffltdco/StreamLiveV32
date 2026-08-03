<?php
require_once __DIR__ . '/functions.php';
if (is_file(__DIR__ . '/prod_hardening.php')) {
  require_once __DIR__ . '/prod_hardening.php';
}

function ensure_auth_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;

  try {
    $pdo = db();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!$dbName) return;

    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('totp_secret','totp_enabled','must_change_password','phone')");
    $stmt->execute([$dbName]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if (!in_array('totp_secret', $existing, true)) {
      $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL');
    }
    if (!in_array('totp_enabled', $existing, true)) {
      $pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0');
    }
    if (!in_array('must_change_password', $existing, true)) {
      $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0');
    }
    if (!in_array('phone', $existing, true)) {
      $pdo->exec('ALTER TABLE users ADD COLUMN phone VARCHAR(32) DEFAULT NULL');
    }
  } catch (\Throwable $e) {
    error_log('[auth schema] ' . $e->getMessage());
  }
}
ensure_auth_schema();
if (function_exists('sl_maintenance_guard')) {
  try { sl_maintenance_guard(); } catch (\Throwable $e) {}
}

function auth_canonical_path(?string $path): string {
  $path = '/' . ltrim((string)($path ?: '/'), '/');
  if ($path !== '/' && substr($path, -1) === '/') {
    $path = rtrim($path, '/');
  }
  if (substr($path, -4) === '.php') {
    $path = substr($path, 0, -4);
  }
  return $path ?: '/';
}

function auth_path_is(array $paths, ?string $currentPath = null): bool {
  $current = auth_canonical_path($currentPath ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
  foreach ($paths as $path) {
    if ($current === auth_canonical_path($path)) return true;
  }
  return false;
}

function auth_path_starts_with(string $prefix, ?string $currentPath = null): bool {
  $current = auth_canonical_path($currentPath ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
  return strpos($current, auth_canonical_path($prefix)) === 0;
}

function normalize_auth_redirect_target($next, string $fallback = '/dashboard.php'): string {
  if (!is_string($next)) return $fallback;
  $next = trim($next);
  if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || preg_match('/[\r\n]/', $next)) return $fallback;

  $nextPath = parse_url($next, PHP_URL_PATH) ?: '/';
  $blockedExact = [
    '/auth/login.php', '/auth/register.php', '/auth/2fa_setup.php', '/auth/2fa_verify.php',
    '/auth/oauth_start.php', '/auth/oauth_callback.php', '/auth/logout.php',
  ];
  if (auth_path_is($blockedExact, $nextPath) || auth_path_starts_with('/admin/', $nextPath)) return $fallback;

  return $next;
}

function safe_login_redirect(): string {
  $path = normalize_auth_redirect_target($_SERVER['REQUEST_URI'] ?? '/', '/dashboard.php');
  $_SESSION['login_next'] = $path;
  return '/auth/login.php?next=' . rawurlencode($path);
}

function safe_after_login_redirect(): string {
  $fallback = normalize_auth_redirect_target($_SESSION['login_next'] ?? '', '/dashboard.php');
  return normalize_auth_redirect_target($_POST['next'] ?? $_GET['next'] ?? '', $fallback);
}

function current_user(): ?array {
  static $user = null;
  static $loaded = false;
  if ($loaded) return $user;
  $loaded = true;
  if (empty($_SESSION['user_id'])) return null;
  $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
  $stmt->execute([$_SESSION['user_id']]);
  $user = $stmt->fetch() ?: null;
  if ($user && $user['is_banned']) { $user = null; }
  return $user;
}

function login_user(int $userId): void {
  session_regenerate_id(true);
  $_SESSION['user_id'] = $userId;

  // Снимаем автостоп задеплоенных сервисов (30 дней неактивности) — раньше такой функции
  // не было вообще, и услуга оставалась "приостановлена" навсегда, даже если владелец
  // возвращался и активно пользовался платформой. Не трогает сервисы, заблокированные
  // модератором вручную за нарушение (см. deployed_services_resume_for_user()).
  try {
    require_once __DIR__ . '/service_helpers.php';
    deployed_services_resume_for_user($userId);
  } catch (\Throwable $e) { }

  require_once __DIR__ . '/gamification.php';
  register_daily_activity($userId);
}

function logout_user(): void {
  $_SESSION = [];
  session_destroy();
}

function require_login(): array {
  $user = current_user();
  if (!$user) {
    flash_set('error', 'Нужно войти в аккаунт');
    redirect(safe_login_redirect());
  }
  $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $allowedWhilePending = ['/auth/force_password_change.php', '/auth/logout.php'];
  if (!empty($user['must_change_password']) && !auth_path_is($allowedWhilePending, $currentPath)) {
    redirect('/auth/force_password_change.php');
  }
  return $user;
}

function require_admin(): array {
  $user = require_login();
  if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Доступ только для администраторов');
  }
  return $user;
}

function is_channel_moderator(int $channelId, int $userId): bool {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id FROM channels WHERE id = ? AND owner_id = ?');
  $stmt->execute([$channelId, $userId]);
  if ($stmt->fetch()) return true;
  $stmt = $pdo->prepare('SELECT id FROM chat_moderators WHERE channel_id = ? AND user_id = ?');
  $stmt->execute([$channelId, $userId]);
  return (bool)$stmt->fetch();
}

// Модератор форума — либо сайт-админ, либо назначен через /admin/forum.php
function is_forum_moderator(?array $user): bool {
  if (!$user) return false;
  if ($user['role'] === 'admin') return true;
  static $cache = [];
  if (array_key_exists($user['id'], $cache)) return $cache[$user['id']];
  $stmt = db()->prepare('SELECT id FROM forum_moderators WHERE user_id = ?');
  $stmt->execute([$user['id']]);
  return $cache[$user['id']] = (bool)$stmt->fetch();
}

// Секрет для подписи 2FA-токенов (в отдельной настройке БД, генерируется один раз сам).
// Нужен, чтобы 2FA переживала переход по ссылке даже если сессия/куки не долетели
// (частая история с мобильными браузерами и OAuth-редиректами).
function totp_token_secret(): string {
  $secret = get_setting('totp_token_secret');
  if (!$secret) {
    $secret = bin2hex(random_bytes(32));
    set_setting('totp_token_secret', $secret);
  }
  return $secret;
}

// Короткоживущий подписанный токен вида "userId.expiresAt.signature" (base64url).
// НЕ хранит пароль/секреты — только id пользователя и время жизни (10 минут).
function make_2fa_token(int $userId): string {
  $exp = time() + 600;
  $payload = $userId . '.' . $exp;
  $sig = hash_hmac('sha256', $payload, totp_token_secret());
  $raw = $payload . '.' . $sig;
  return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

// Проверяет токен из make_2fa_token(). Возвращает id пользователя либо null, если
// токен неверный, испорчен или просрочен.
function verify_2fa_token(string $token): ?int {
  $raw = base64_decode(strtr($token, '-_', '+/'));
  if ($raw === false) return null;
  $parts = explode('.', $raw, 3);
  if (count($parts) !== 3) return null;
  [$userId, $exp, $sig] = $parts;
  if (!ctype_digit($userId) || !ctype_digit($exp)) return null;
  $expected = hash_hmac('sha256', $userId . '.' . $exp, totp_token_secret());
  if (!hash_equals($expected, $sig)) return null;
  if ((int)$exp < time()) return null;
  return (int)$userId;
}

// Обязательная 2FA: без включённого TOTP доступ к аккаунту закрыт.
// Вызывается при каждом require_once includes/auth.php — покрывает все страницы и action-эндпоинты.
// ВАЖНО: по умолчанию ВЫКЛЮЧЕНА (килл-свитч ниже) — включается вручную в /admin/security.php
// после того как админ сам проверит вход на ПК и телефоне. Так одна проблема с 2FA больше
// никогда не положит весь сайт целиком.
function enforce_2fa_gate(): void {
  if (get_setting('force_2fa_enabled', '0') !== '1') return; // килл-свитч, по умолчанию выключено

  if (empty($_SESSION['user_id']) && empty($_SESSION['pending_2fa_user_id'])) return;

  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $allowed = [
    '/auth/2fa_setup.php', '/auth/2fa_verify.php', '/auth/logout.php',
    '/embed.php', '/now_playing.php',
    '/chat_poll.php', '/chat_send.php', '/chat_delete.php', '/chat_ban.php',
    '/channel_like.php', '/channel_favorite.php', '/short_like.php',
    '/ai_send.php', '/ai_project_save.php',
    '/message_poll.php', '/message_send.php',
  ];
  if (auth_path_is($allowed, $path)) return;

  try {
    if (!empty($_SESSION['pending_2fa_user_id'])) {
      $stmt = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
      $stmt->execute([$_SESSION['pending_2fa_user_id']]);
      $enabled = (bool)$stmt->fetchColumn();
      redirect($enabled ? '/auth/2fa_verify.php' : '/auth/2fa_setup.php');
    }

    if (!empty($_SESSION['user_id'])) {
      $stmt = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
      $stmt->execute([$_SESSION['user_id']]);
      $enabled = (bool)$stmt->fetchColumn();
      if (!$enabled) redirect('/auth/2fa_setup.php');
    }
  } catch (\Throwable $e) {
    // ВАЖНО: раньше любая ошибка здесь (например не накаченная миграция — нет колонки
    // totp_enabled) молча пропускала пользователя БЕЗ 2FA — то есть "тихо" отключала
    // защиту при любой проблеме с БД. Это дыра: fail-open вместо fail-closed.
    // Теперь при ошибке — разлогиниваем и отправляем на вход с понятным сообщением,
    // вместо того чтобы пускать без проверки.
    error_log('[2FA gate] ' . $e->getMessage());
    $_SESSION = [];
    session_destroy();
    session_start();
    flash_set('error', 'Ошибка проверки 2FA. Схема БД проверена автоматически; попробуйте войти ещё раз.');
    redirect('/auth/login.php');
  }
}
enforce_2fa_gate();

// Требуем номер телефона у уже вошедших пользователей, у которых его ещё нет
// (завели аккаунт до этой функции, или пришли через OAuth-соцсеть без телефона).
// Без номера — запасного способа входа при потере почты не будет, поэтому просим
// указать сразу. SMS не отправляем, только формат проверяем.
function enforce_phone_gate(): void {
  if (empty($_SESSION['user_id'])) return;

  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
  $allowed = [
    '/auth/add_phone.php', '/auth/logout.php',
    '/embed.php', '/now_playing.php',
    '/chat_poll.php', '/chat_send.php', '/chat_delete.php', '/chat_ban.php',
    '/channel_like.php', '/channel_favorite.php', '/short_like.php',
    '/ai_send.php', '/ai_project_save.php',
    '/message_poll.php', '/message_send.php', '/broadcast_post_poll.php', '/broadcast_post_send.php',
  ];
  if (auth_path_is($allowed, $path)) return;
  if (auth_path_starts_with('/admin/', $path)) return;

  try {
    $stmt = db()->prepare('SELECT phone FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $phone = $stmt->fetchColumn();
    if (!$phone) redirect('/auth/add_phone.php');
  } catch (\Throwable $e) {
    // В отличие от 2FA — это не защита безопасности, а просто удобство восстановления
    // доступа. Если миграция ещё не накатана или БД временно недоступна — не блокируем
    // весь сайт из-за этого, просто пропускаем проверку на этот раз.
    return;
  }
}
enforce_phone_gate();
