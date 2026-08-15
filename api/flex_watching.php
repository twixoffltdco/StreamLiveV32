<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/flex_watching.php';

$u = current_user();
if (!$u) {
  echo json_encode(['ok' => false, 'error' => 'login']);
  exit;
}
$uid = (int)$u['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
  $body = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($body)) $body = $_POST;
  if (!empty($body['clear'])) {
    flex_watching_clear($uid);
    echo json_encode(['ok' => true, 'cleared' => true]);
    exit;
  }
  flex_watching_set(
    $uid,
    (string)($body['title'] ?? 'контент'),
    (string)($body['url'] ?? ''),
    (string)($body['source'] ?? 'video')
  );
  echo json_encode(['ok' => true]);
  exit;
}

$w = flex_watching_get($uid);
echo json_encode(['ok' => true, 'watching' => $w], JSON_UNESCAPED_UNICODE);
