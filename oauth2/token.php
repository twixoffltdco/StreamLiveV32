<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$clientId = $_POST['client_id'] ?? '';
$clientSecret = $_POST['client_secret'] ?? '';
$code = $_POST['code'] ?? '';

$stmt = db()->prepare('SELECT * FROM oauth_apps WHERE client_id = ?');
$stmt->execute([$clientId]);
$app = $stmt->fetch();

if (!$app || !password_verify($clientSecret, $app['client_secret_hash'])) {
  http_response_code(401);
  echo json_encode(['error' => 'invalid_client']);
  exit;
}

$stmt = db()->prepare('SELECT * FROM oauth_auth_codes WHERE code = ? AND app_id = ?');
$stmt->execute([$code, $app['id']]);
$authCode = $stmt->fetch();

if (!$authCode || $authCode['used'] || strtotime($authCode['expires_at']) < time()) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_grant']);
  exit;
}

db()->prepare('UPDATE oauth_auth_codes SET used = 1 WHERE code = ?')->execute([$code]);

$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', time() + 30 * 86400); // 30 дней
db()->prepare('INSERT INTO oauth_access_tokens (token, app_id, user_id, expires_at) VALUES (?, ?, ?, ?)')
  ->execute([$token, $app['id'], $authCode['user_id'], $expiresAt]);

echo json_encode([
  'access_token' => $token,
  'token_type' => 'Bearer',
  'expires_in' => 30 * 86400,
]);
