<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/api_auth.php';
header('Content-Type: application/json; charset=utf-8');

$apiState = api_guard();
if (!$apiState['ok']) exit; // 401 или 503

$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$type = $_GET['type'] ?? null;
if ($type && !in_array($type, ['tv', 'radio'])) {
    $type = null;
}

$sql = "SELECT id, slug, title, logo_url, type, views, description
        FROM channels
        WHERE status = 'approved'";
$params = [];
if ($type) {
    $sql .= " AND type = ?";
    $params[] = $type;
}
$sql .= " ORDER BY views DESC, created_at DESC LIMIT ?";
$params[] = $limit;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$channels = $stmt->fetchAll();

echo json_encode([
    'ok' => true,
    'authenticated' => $apiState['authenticated'],
    'warning' => $apiState['warning'],
    'channels' => $channels,
]);