<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
if (is_file(dirname(__DIR__) . '/includes/now_watching.php')) require_once dirname(__DIR__) . '/includes/now_watching.php';

$u = function_exists('current_user') ? current_user() : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$u) { echo json_encode(['ok'=>false]); exit; }
  $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  now_watching_set(
    (int)$u['id'],
    (string)($body['type'] ?? 'video'),
    (int)($body['id'] ?? 0),
    (string)($body['title'] ?? ''),
    (string)($body['url'] ?? '')
  );
  echo json_encode(['ok'=>true]);
  exit;
}
echo json_encode(['ok'=>true, 'items'=> now_watching_list(40)], JSON_UNESCAPED_UNICODE);
