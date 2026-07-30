<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/api_auth.php';
header('Content-Type: application/json; charset=utf-8');

$apiState = api_guard();
if (!$apiState['ok']) exit; // ответ уже отправлен (401 неверный ключ, либо 503 блок без ключа при атаке)

$limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$stmt = db()->prepare(
  "SELECT v.slug, v.title, v.thumbnail_url, v.views_count, c.title AS channel_title
   FROM videos v JOIN channels c ON c.id = v.channel_id
   WHERE v.status = 'published' ORDER BY v.created_at DESC LIMIT ?"
);
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->execute();

echo json_encode([
  'ok' => true,
  'authenticated' => $apiState['authenticated'],
  'warning' => $apiState['warning'],
  'videos' => $stmt->fetchAll(),
]);
