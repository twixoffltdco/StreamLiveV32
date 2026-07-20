<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
  http_response_code(401);
  echo json_encode(['error' => 'missing_token']);
  exit;
}
$token = $m[1];

$stmt = db()->prepare('SELECT * FROM oauth_access_tokens WHERE token = ?');
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row || strtotime($row['expires_at']) < time()) {
  http_response_code(401);
  echo json_encode(['error' => 'invalid_token']);
  exit;
}

$stmt = db()->prepare('SELECT id, username, avatar, is_verified FROM users WHERE id = ?');
$stmt->execute([$row['user_id']]);
$user = $stmt->fetch();

if (!$user) {
  http_response_code(404);
  echo json_encode(['error' => 'user_not_found']);
  exit;
}

echo json_encode([
  'id' => (int)$user['id'],
  'username' => $user['username'],
  'avatar' => $user['avatar'],
  'is_verified' => (bool)$user['is_verified'],
]);
