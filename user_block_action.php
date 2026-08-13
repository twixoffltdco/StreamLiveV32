<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/contacts.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Войдите']);
  exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$action = (string)($input['action'] ?? 'block');
$targetId = (int)($input['user_id'] ?? 0);

contacts_ensure_schema();

if ($targetId <= 0 || $targetId === (int)$user['id']) {
  echo json_encode(['ok' => false, 'error' => 'Некорректный пользователь']);
  exit;
}

try {
  // нельзя блокировать админов? можно в ЧС, но не жаловаться — блок личный ок
  if ($action === 'unblock') {
    $ok = contacts_unblock((int)$user['id'], $targetId);
    echo json_encode(['ok' => $ok, 'blocked' => false]);
    exit;
  }
  $ok = contacts_block((int)$user['id'], $targetId);
  echo json_encode(['ok' => $ok, 'blocked' => true]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
}
