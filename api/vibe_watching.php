<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/vibe.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (is_file(dirname(__DIR__) . '/includes/poll_throttle.php')) {
  require_once dirname(__DIR__) . '/includes/poll_throttle.php';
  if (function_exists('poll_throttle')) poll_throttle('vibe_watch', 60);
}
$cid = (int)($_GET['channel_id'] ?? $_POST['channel_id'] ?? 0);
$key = (string)($_GET['sk'] ?? $_POST['sk'] ?? '');
if ($key === '') $key = substr(md5(($_SERVER['REMOTE_ADDR'] ?? '') . session_id()), 0, 32);
$u = function_exists('current_user') ? current_user() : null;
$n = vibe_watching_ping($cid, $key, $u ? (int)$u['id'] : 0);
echo json_encode(['ok'=>true,'watching'=>$n]);
