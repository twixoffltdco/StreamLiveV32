<?php
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/mod_bots.php';
if (is_file($root . '/includes/social_bots.php')) {
  require_once $root . '/includes/social_bots.php';
}
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
  $key = $_GET['key'] ?? '';
  $exp = function_exists('get_setting') ? (string)get_setting('bots_worker_key', '') : '';
  if ($exp === '' || !hash_equals($exp, (string)$key)) {
    http_response_code(403);
    exit('Forbidden');
  }
}
$a = mod_bots_process_queue(25);
$b = function_exists('bots_process_queue') ? bots_process_queue(25) : ['done'=>0,'error'=>0];
if ($isCli) echo "mod={$a['done']}/{$a['error']} platform={$b['done']}/{$b['error']}\n";
else { header('Content-Type: application/json'); echo json_encode(['mod'=>$a,'platform'=>$b]); }
