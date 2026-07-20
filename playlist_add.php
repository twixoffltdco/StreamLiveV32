<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user = current_user();
if (!$user) { echo json_encode(['ok' => false, 'error' => 'Нужно войти']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$videoId = (int)($input['video_id'] ?? 0);
$playlistId = (int)($input['playlist_id'] ?? 0);
$newTitle = trim((string)($input['new_title'] ?? ''));

// Либо кладём в существующий плейлист (проверяем, что он наш), либо создаём новый на лету
if ($newTitle !== '') {
  $slug = slugify($newTitle);
  db()->prepare('INSERT INTO playlists (user_id, title, slug, is_public) VALUES (?, ?, ?, 1)')->execute([$user['id'], $newTitle, $slug]);
  $playlistId = (int)db()->lastInsertId();
}

$stmt = db()->prepare('SELECT id FROM playlists WHERE id = ? AND user_id = ?');
$stmt->execute([$playlistId, $user['id']]);
if (!$stmt->fetch()) { echo json_encode(['ok' => false, 'error' => 'Плейлист не найден']); exit; }

$stmt = db()->prepare('SELECT COALESCE(MAX(position),0) FROM playlist_items WHERE playlist_id = ?');
$stmt->execute([$playlistId]);
$maxPos = (int)$stmt->fetchColumn();

db()->prepare('INSERT IGNORE INTO playlist_items (playlist_id, video_id, position) VALUES (?, ?, ?)')
  ->execute([$playlistId, $videoId, $maxPos + 1]);

echo json_encode(['ok' => true, 'playlist_id' => $playlistId]);
