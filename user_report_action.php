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
$targetId = (int)($input['user_id'] ?? 0);
$reason = (string)($input['reason'] ?? 'spam');
$severity = (string)($input['severity'] ?? 'medium');

// spam → medium (2 мес при автобане); abuse → strong; other → weak
if ($reason === 'spam' && empty($input['severity'])) $severity = 'medium';
if ($reason === 'abuse' && empty($input['severity'])) $severity = 'strong';
if ($reason === 'other' && empty($input['severity'])) $severity = 'weak';

$result = contacts_report((int)$user['id'], $targetId, $reason, $severity);
if (!empty($result['ok'])) {
  $msg = 'Жалоба принята';
  if (!empty($result['auto_banned'])) {
    $msg = 'Жалоба принята. Аккаунт заблокирован на платформе (10+ жалоб на спам).';
  }
  echo json_encode(['ok' => true, 'message' => $msg, 'auto_banned' => !empty($result['auto_banned'])]);
} else {
  echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Ошибка']);
}
