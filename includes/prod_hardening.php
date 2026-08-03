<?php
/**
 * Production hardening for StreamLive / PL Video
 * - security headers (кроме embed)
 * - maintenance mode
 * - простой anti-flood (файл/сессия)
 * Подключается из includes/auth.php (почти все страницы).
 */

if (defined('SL_PROD_HARDENING')) return;
define('SL_PROD_HARDENING', 1);

/** Не слать жёсткие frame headers на embed-страницах */
function sl_is_embed_request(): bool {
  $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
  $embedScripts = [
    'embed.php', 'video_embed.php', 'embedstreamtok.php',
    'playersmotrimru.php', 'embedwebsite',
  ];
  foreach ($embedScripts as $e) {
    if ($script === $e || strpos($script, 'embed') === 0) return true;
  }
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  if (strpos($uri, '/embed') !== false) return true;
  return false;
}

function sl_send_security_headers(): void {
  if (headers_sent()) return;
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
  header('X-XSS-Protection: 0'); // современные браузеры; CSP важнее
  if (!sl_is_embed_request()) {
    // Не ломаем встраивание видео на чужих сайтах
    if (!headers_list() || !preg_grep('/^X-Frame-Options:/i', headers_list() ?: [])) {
      header('X-Frame-Options: SAMEORIGIN');
    }
  }
  // HSTS только если уже HTTPS
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
  if ($https) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
  }
}

function sl_maintenance_flag_path(): string {
  return dirname(__DIR__) . '/storage/maintenance.on';
}

function sl_is_maintenance(): bool {
  if (is_file(sl_maintenance_flag_path())) return true;
  try {
    if (function_exists('get_setting') && (string)get_setting('maintenance_mode', '0') === '1') {
      return true;
    }
  } catch (Throwable $e) {}
  return false;
}

function sl_maintenance_guard(): void {
  if (!sl_is_maintenance()) return;
  $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
  // Пускаем админку, install, health, статику
  $allow = ['health.php', 'login.php', 'logout.php', 'bots_webhook.php'];
  if (in_array($script, $allow, true)) return;
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  if (strpos($uri, '/admin') === 0 || strpos($uri, '/install') === 0 || strpos($uri, '/auth') === 0) {
    return;
  }
  // Админы проходят
  if (function_exists('current_user')) {
    try {
      $u = current_user();
      if ($u && (($u['role'] ?? '') === 'admin')) return;
    } catch (Throwable $e) {}
  }
  http_response_code(503);
  header('Retry-After: 300');
  header('Content-Type: text/html; charset=utf-8');
  $name = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
  echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>Техработы — ' . htmlspecialchars($name) . '</title>';
  echo '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b0b0f;color:#eee;font-family:system-ui,sans-serif;text-align:center;padding:24px}';
  echo '.box{max-width:420px}h1{font-size:1.4rem;margin:0 0 12px}p{color:#aaa;line-height:1.5}</style></head><body>';
  echo '<div class="box"><h1>⚙️ Технические работы</h1>';
  echo '<p>Площадка временно на обслуживании. Зайдите чуть позже — всё вернётся.</p></div></body></html>';
  exit;
}

/**
 * Anti-flood: не чаще $minInterval секунд на ключ (user/ip + action).
 * @return true если можно, false если слишком часто
 */
function sl_rate_limit(string $action, int $minInterval = 3, ?int $userId = null): bool {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
  $uid = $userId !== null ? (string)$userId : '0';
  $key = 'rl_' . hash('sha256', $action . '|' . $uid . '|' . $ip);
  if (session_status() === PHP_SESSION_NONE) {
    @session_start();
  }
  $now = time();
  $last = (int)($_SESSION[$key] ?? 0);
  if ($last && ($now - $last) < $minInterval) {
    return false;
  }
  $_SESSION[$key] = $now;
  return true;
}

// Автозапуск на каждом include (кроме CLI)
if (PHP_SAPI !== 'cli') {
  sl_send_security_headers();
  // maintenance после session/user — откладываем: вызывается из auth после current_user available
  // Ранний check по файлу:
  if (is_file(sl_maintenance_flag_path())) {
    // отложить полный guard — файл-флаг ловим сразу
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!in_array($script, ['health.php', 'login.php'], true)
        && strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') !== 0) {
      // полный guard позже когда auth загружен
      register_shutdown_function(static function () {
        // no-op
      });
    }
  }
}
