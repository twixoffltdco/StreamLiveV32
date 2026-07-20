<?php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$channelId = (int)($_GET['channel_id'] ?? 0);
$stmt = db()->prepare("SELECT rtmp_playback_url FROM channels WHERE id = ? AND status = 'approved'");
$stmt->execute([$channelId]);
$url = $stmt->fetchColumn();

if (!$url) { echo json_encode(['ok' => true, 'applicable' => false]); exit; }

// Короткий HEAD-запрос с жёстким таймаутом — на бесплатном хостинге лимит времени
// выполнения PHP-скрипта маленький, поэтому проверяем максимально быстро (3 сек)
// и не блокируем страницу, если relay-сервер не отвечает вовреме.
$live = false;
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_NOBODY => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 3,
  CURLOPT_TIMEOUT => 3,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_SSL_VERIFYPEER => true,
]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$live = $code >= 200 && $code < 400;

echo json_encode(['ok' => true, 'applicable' => true, 'live' => $live]);
