<?php
/** Лёгкий вызов очистки раз в 5 часов (не на каждый hit) */
try {
  $marker = dirname(__DIR__) . '/storage/cache/last_cleanup.txt';
  $dir = dirname($marker);
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $need = !is_file($marker) || (time() - filemtime($marker) > 5 * 3600);
  if ($need) {
    // не блокируем ответ пользователю — ignore abort
    if (function_exists('fastcgi_finish_request')) {
      // will run after in same request still - just include
    }
    $cron = dirname(__DIR__) . '/cron/storage_cleanup.php';
    if (is_file($cron)) {
      // run quietly
      ob_start();
      include $cron;
      ob_end_clean();
    }
  }
} catch (Throwable $e) {}
