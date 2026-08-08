<?php
/**
 * Серверный троттлинг poll-эндпоинтов (InfinityFree hits).
 * Повторный запрос от того же IP раньше $minSeconds → 204 без работы БД.
 */
function poll_throttle(string $bucket, int $minSeconds = 120): void {
  $minSeconds = max(60, (int)$minSeconds);
  $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '0';
  if (strpos((string)$ip, ',') !== false) {
    $ip = trim(explode(',', (string)$ip)[0]);
  }
  $dir = dirname(__DIR__) . '/storage/poll_throttle';
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  $key = preg_replace('/[^a-zA-Z0-9_-]/', '', $bucket . '_' . md5((string)$ip));
  $file = $dir . '/' . $key . '.txt';
  $now = time();
  if (is_file($file)) {
    $last = (int)@file_get_contents($file);
    if ($last > 0 && ($now - $last) < $minSeconds) {
      http_response_code(204);
      header('Cache-Control: no-store');
      header('X-Poll-Throttle: 1');
      exit;
    }
  }
  @file_put_contents($file, (string)$now, LOCK_EX);
}
