<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/vibe.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (is_file(dirname(__DIR__) . '/includes/poll_throttle.php')) {
  require_once dirname(__DIR__) . '/includes/poll_throttle.php';
  if (function_exists('poll_throttle')) poll_throttle('vibe_react', 2);
}
$u = function_exists('current_user') ? current_user() : null;
if (!$u) { echo json_encode(['ok'=>false,'error'=>'login']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'error'=>'POST']); exit; }
if (function_exists('csrf_verify')) { try { csrf_verify(); } catch (Throwable $e) {} }
$postId = (int)($_POST['post_id'] ?? 0);
$emoji = (string)($_POST['emoji'] ?? '');
echo json_encode(vibe_reaction_toggle($postId, (int)$u['id'], $emoji), JSON_UNESCAPED_UNICODE);
