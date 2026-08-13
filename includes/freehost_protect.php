<?php
/**
 * Защита free-хостинга (InfinityFree / blyz / vista):
 * меньше хитов от ботов, rate-limit по IP, жёсткий отказ без тяжёлого PHP.
 * Подключать как можно раньше (из functions.php до тяжёлой логики).
 */
if (defined('SL_FREEHOST_PROTECT')) return;
define('SL_FREEHOST_PROTECT', 1);

function freehost_protect_client_ip(): string {
  if (function_exists('antibot_client_ip')) {
    return (string)antibot_client_ip();
  }
  foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
    if (!empty($_SERVER[$h])) {
      $v = trim(explode(',', (string)$_SERVER[$h])[0]);
      if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
    }
  }
  return '0.0.0.0';
}

function freehost_protect_is_staff(): bool {
  if (session_status() === PHP_SESSION_NONE) {
    @session_start();
  }
  if (empty($_SESSION['user_id'])) return false;
  // быстрый путь: не ходим в БД на каждый хит — роль кэшируем в сессии
  if (!empty($_SESSION['_fh_staff'])) return true;
  return false;
}

function freehost_protect_mark_staff_from_db(): void {
  if (empty($_SESSION['user_id']) || !function_exists('db')) return;
  try {
    $st = db()->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)$_SESSION['user_id']]);
    $role = (string)($st->fetchColumn() ?: '');
    if (in_array($role, ['admin', 'moderator'], true)) {
      $_SESSION['_fh_staff'] = 1;
    } else {
      unset($_SESSION['_fh_staff']);
    }
  } catch (Throwable $e) {}
}

function freehost_protect_block(int $code = 429, string $msg = 'Too many requests'): void {
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  header('Retry-After: 60');
  echo $msg;
  exit;
}

function freehost_protect_bad_ua(string $ua): bool {
  $ua = strtolower(trim($ua));
  if ($ua === '' || strlen($ua) < 12) return true;
  $bad = [
    'curl/', 'wget/', 'python-requests', 'python-urllib', 'scrapy', 'httpclient',
    'go-http-client', 'java/', 'libwww', 'okhttp', 'aiohttp', 'httpx',
    'semrush', 'ahrefs', 'mj12bot', 'dotbot', 'petalbot', 'bytespider',
    'gptbot', 'ccbot', 'amazonbot', 'dataforseo', 'serpstat', 'screaming frog',
    'masscan', 'zgrab', 'nuclei', 'sqlmap',
  ];
  foreach ($bad as $b) {
    if (strpos($ua, $b) !== false) return true;
  }
  return false;
}

/** Лимит хитов с одного IP: $max за $windowSec (только гости / не-staff) */
function freehost_protect_rate_limit(string $ip, int $max = 90, int $windowSec = 60): bool {
  $dir = rtrim(sys_get_temp_dir(), '/\\') . '/sl_fh_rl';
  if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
  }
  $file = $dir . '/' . hash('sha256', $ip) . '.json';
  $now = time();
  $data = ['t' => $now, 'c' => 0];
  if (is_file($file)) {
    $raw = @file_get_contents($file);
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j) && isset($j['t'], $j['c']) && ($now - (int)$j['t']) < $windowSec) {
      $data = ['t' => (int)$j['t'], 'c' => (int)$j['c']];
    }
  }
  $data['c']++;
  @file_put_contents($file, json_encode($data), LOCK_EX);
  return $data['c'] <= $max;
}

function freehost_protect_guard(): void {
  // Не режем CLI
  if (PHP_SAPI === 'cli') return;

  $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
  // Статика — пусть отдаёт веб-сервер, но если PHP всё же вызван — не душим
  if (preg_match('#\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|map)$#i', $path)) {
    return;
  }

  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $ip = freehost_protect_client_ip();

  // Явные боты / пустой UA — 403 сразу (экономия CPU и хитов)
  if (freehost_protect_bad_ua($ua)) {
    // разрешить легитимных краулеров по желанию (Googlebot и т.п. — лёгкий allow)
    $ual = strtolower($ua);
    $allowBots = ['googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'applebot'];
    $ok = false;
    foreach ($allowBots as $ab) {
      if (strpos($ual, $ab) !== false) { $ok = true; break; }
    }
    if (!$ok) {
      freehost_protect_block(403, 'Forbidden');
    }
  }

  if (session_status() === PHP_SESSION_NONE) {
    @session_start();
  }
  // staff / залогиненные — мягче
  if (!empty($_SESSION['user_id'])) {
    return;
  }

  // Гости: rate limit ~90 req/min с IP (подкрутить при атаке)
  $max = 90;
  if (function_exists('get_setting')) {
    try {
      $v = (int)get_setting('freehost_guest_rpm', '90');
      if ($v >= 20 && $v <= 600) $max = $v;
    } catch (Throwable $e) {}
  }
  if (!freehost_protect_rate_limit($ip, $max, 60)) {
    freehost_protect_block(429, 'Rate limit. Slow down.');
  }
}

// Автозапуск при include
freehost_protect_guard();
