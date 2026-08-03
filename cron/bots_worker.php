<?php
/**
 * CLI / cron worker: php cron/bots_worker.php
 * Или HTTP: /cron/bots_worker.php?key=SECRET
 */
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/social_bots.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
  // HTTP: нужен ключ из settings или bots_worker_key
  $key = $_GET['key'] ?? '';
  $expected = '';
  try {
    if (function_exists('get_setting')) {
      $expected = (string)get_setting('bots_worker_key', '');
    }
  } catch (Throwable $e) {}
  if ($expected === '' || !hash_equals($expected, (string)$key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
  }
}

bots_ensure_schema();
$stats = bots_process_queue(25);

if ($isCli) {
  echo "done={$stats['done']} error={$stats['error']}\n";
} else {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true] + $stats);
}
