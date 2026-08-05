<?php
/**
 * Лёгкий rate-limit для poll/API на shared-хостинге (InfinityFree и т.п.).
 * Без Redis: файлы в sys_get_temp_dir() или storage/cache.
 * Не заменяет лимиты хостинга, но режет частые опросы с одного IP.
 */
if (!function_exists('poll_throttle_check')) {

  function poll_throttle_dir(): string {
    $dir = __DIR__ . '/../storage/cache/poll_rl';
    if (!is_dir($dir)) {
      @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
      return $dir;
    }
    $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/sl_poll_rl';
    if (!is_dir($tmp)) {
      @mkdir($tmp, 0755, true);
    }
    return $tmp;
  }

  function poll_throttle_ip(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
      ?? $_SERVER['HTTP_X_FORWARDED_FOR']
      ?? $_SERVER['REMOTE_ADDR']
      ?? '0';
    if (strpos($ip, ',') !== false) {
      $ip = trim(explode(',', $ip)[0]);
    }
    return preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0';
  }

  /**
   * @param string $bucket  имя эндпоинта (message_poll, notif, …)
   * @param int    $max     макс. запросов за окно
   * @param int    $window  секунды
   * @return bool true = можно, false = лимит (уже отправлен 429)
   */
  function poll_throttle_check(string $bucket, int $max = 20, int $window = 60): bool {
    // не душит админов/модеров сильно — чуть выше лимит
    $max = max(5, $max);
    $window = max(10, $window);
    $ip = poll_throttle_ip();
    $key = md5($bucket . '|' . $ip);
    $file = poll_throttle_dir() . '/' . $key . '.json';
    $now = time();
    $data = ['t' => $now, 'c' => 0];
    if (is_file($file)) {
      $raw = @file_get_contents($file);
      $j = $raw ? json_decode($raw, true) : null;
      if (is_array($j) && isset($j['t'], $j['c'])) {
        if (($now - (int)$j['t']) < $window) {
          $data = $j;
        }
      }
    }
    if (($now - (int)$data['t']) >= $window) {
      $data = ['t' => $now, 'c' => 0];
    }
    $data['c'] = (int)$data['c'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    // иногда чистим старые файлы
    if (mt_rand(1, 40) === 1) {
      foreach (glob(poll_throttle_dir() . '/*.json') ?: [] as $f) {
        if (@filemtime($f) < $now - 3600) {
          @unlink($f);
        }
      }
    }

    if ((int)$data['c'] > $max) {
      if (!headers_sent()) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $window);
        header('Cache-Control: no-store');
      }
      echo json_encode(['ok' => false, 'error' => 'rate_limit', 'retry_after' => $window]);
      return false;
    }
    return true;
  }
}
