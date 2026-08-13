<?php
if (!function_exists('poll_throttle')) {
function poll_throttle(string $bucket, int $sec = 180): void {
  $sec = max(120, $sec);
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
  $dir = sys_get_temp_dir() . '/sl_poll_t';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  $file = $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $bucket) . '_' . md5($ip) . '.ttl';
  $now = time();
  if (is_file($file)) {
    $last = (int)@file_get_contents($file);
    if ($last > 0 && ($now - $last) < $sec) {
      header('Content-Type: application/json; charset=utf-8');
      http_response_code(429);
      echo '{"ok":true,"items":[],"threads":[],"throttled":true}';
      exit;
    }
  }
  @file_put_contents($file, (string)$now, LOCK_EX);
}
}
