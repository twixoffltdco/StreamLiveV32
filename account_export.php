<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$u = current_user();
if (!$u) { http_response_code(403); exit('login'); }
$uid = (int)$u['id'];
$pdo = db();

$channels = [];
try {
  $st = $pdo->prepare('SELECT * FROM channels WHERE owner_id = ? OR user_id = ?');
  $st->execute([$uid, $uid]);
  $channels = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $st = $pdo->prepare('SELECT * FROM channels WHERE owner_id = ?');
    $st->execute([$uid]);
    $channels = $st->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}

$pack = [
  'exported_at' => date('c'),
  'format' => 'account_backup_v1',
  'user' => ['id' => $uid, 'username' => $u['username'] ?? ''],
  'channels' => [],
];

foreach ($channels as $ch) {
  if (isset($ch['stream_key'])) unset($ch['stream_key']);
  $cid = (int)$ch['id'];
  $entry = ['channel' => $ch, 'schedule' => [], 'videos' => [], 'sources' => []];
  try {
    $s = $pdo->prepare('SELECT * FROM schedule WHERE channel_id = ?');
    $s->execute([$cid]);
    $entry['schedule'] = $s->fetchAll() ?: [];
  } catch (Throwable $e) {}
  try {
    $s = $pdo->prepare('SELECT id,slug,title,description,tags,source_url,platform,embed_url,thumbnail_url,status,premiere_at,is_premiere,is_short,clip_start,clip_end FROM videos WHERE channel_id = ?');
    $s->execute([$cid]);
    $entry['videos'] = $s->fetchAll() ?: [];
  } catch (Throwable $e) {}
  $pack['channels'][] = $entry;
}

try {
  $s = $pdo->prepare('SELECT id,name,type,url FROM sources WHERE created_by = ?');
  $s->execute([$uid]);
  $pack['sources'] = $s->fetchAll() ?: [];
} catch (Throwable $e) { $pack['sources'] = []; }

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="account_' . preg_replace('/\W+/','_', (string)($u['username']??'user')) . '_' . date('Ymd_His') . '.json"');
echo json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
