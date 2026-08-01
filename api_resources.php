<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/api_auth.php';
header('Content-Type: application/json; charset=utf-8');

$apiState = api_guard();
if (!$apiState['ok']) exit;

$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

// Предполагается, что в таблице resources есть поле status = 'published'
$stmt = db()->prepare(
    "SELECT r.id, r.title, r.summary, r.url, r.tags, r.created_at, u.username
     FROM resources r
     JOIN users u ON u.id = r.user_id
     WHERE r.status = 'published'
     ORDER BY r.created_at DESC LIMIT ?"
);
$stmt->execute([$limit]);
$resources = $stmt->fetchAll();

echo json_encode([
    'ok' => true,
    'authenticated' => $apiState['authenticated'],
    'warning' => $apiState['warning'],
    'resources' => $resources,
]);