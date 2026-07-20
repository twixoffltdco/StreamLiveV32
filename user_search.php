<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['users' => []]); exit; }

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['users' => []]); exit; }

$stmt = db()->prepare(
  "SELECT id, username, avatar, gravatar_email FROM users
   WHERE username LIKE ? AND id != ? AND is_banned = 0
   ORDER BY username ASC LIMIT 15"
);
$stmt->execute(['%' . $q . '%', $user['id']]);
$rows = $stmt->fetchAll();

echo json_encode(['users' => array_map(function ($r) {
  return ['id' => (int)$r['id'], 'username' => $r['username'], 'avatar' => user_avatar_url($r, 48)];
}, $rows)]);
