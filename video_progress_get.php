<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false]); exit; }

$videoId = (int)($_GET['video_id'] ?? 0);
$stmt = db()->prepare('SELECT position_seconds FROM video_watch_progress WHERE user_id = ? AND video_id = ?');
$stmt->execute([$user['id'], $videoId]);
$position = $stmt->fetchColumn();

echo json_encode(['ok' => $position !== false, 'position' => (int)($position ?: 0)]);
