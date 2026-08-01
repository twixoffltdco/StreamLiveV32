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
$action = (string)($input['action'] ?? '');
$targetId = (int)($input['user_id'] ?? 0);

contacts_ensure_schema();
contacts_maybe_unban((int)$user['id']);

if ($targetId <= 0 || $targetId === (int)$user['id']) {
  echo json_encode(['ok' => false, 'error' => 'Некорректный пользователь']);
  exit;
}

try {
  if ($action === 'add') {
    if (contacts_is_blocked_either((int)$user['id'], $targetId)) {
      echo json_encode(['ok' => false, 'error' => 'Нельзя добавить: есть блокировка']);
      exit;
    }
    $ok = contacts_add((int)$user['id'], $targetId);
    echo json_encode(['ok' => $ok, 'in_contacts' => true, 'mutual' => contacts_are_mutual((int)$user['id'], $targetId)]);
    exit;
  }
  if ($action === 'remove') {
    $ok = contacts_remove((int)$user['id'], $targetId);
    echo json_encode(['ok' => $ok, 'in_contacts' => false, 'mutual' => false]);
    exit;
  }
  echo json_encode(['ok' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok' => false, 'error' => 'Ошибка сервера']);
}
