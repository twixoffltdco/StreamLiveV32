<?php
/**
 * InfinityFree / free hosting: cache headers (меньше хитов).
 * Idle-скрипт подключается из header.php отдельно.
 */
if (defined('SL_FREEHOST_HITS_LOADED')) {
  return;
}
define('SL_FREEHOST_HITS_LOADED', true);

if (!function_exists('sl_freehost_is_guest')) {
  function sl_freehost_is_guest(): bool {
    if (session_status() === PHP_SESSION_NONE) {
      @session_start();
    }
    if (!empty($_SESSION['user_id'])) return false;
    if (!empty($_SESSION['user']['id'])) return false;
    if (!empty($_SESSION['user'])) return false;
    return true;
  }
}

if (!function_exists('sl_freehost_cache_headers')) {
  function sl_freehost_cache_headers(): void {
    if (headers_sent()) return;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
      header('Cache-Control: private, no-store');
      return;
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if (preg_match('#/(admin|moderator|auth|api|messages|dashboard|channel_manage|studio|oauth|login|register)#i', $uri)) {
      header('Cache-Control: private, no-store');
      header('Pragma: no-cache');
      return;
    }
    if (!sl_freehost_is_guest()) {
      header('Cache-Control: private, max-age=0, must-revalidate');
      return;
    }
    header('Cache-Control: public, max-age=120, s-maxage=120');
    header('Vary: Accept-Encoding, Cookie');
  }
}

if (session_status() === PHP_SESSION_NONE) {
  @session_start();
}
sl_freehost_cache_headers();
