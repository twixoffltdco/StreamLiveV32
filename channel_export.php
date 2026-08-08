<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$u = current_user();
if (!$u) { http_response_code(403); exit('login'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('id'); }

$st = db()->prepare('SELECT * FROM channels WHERE id = ?');
$st->execute([$id]);
$ch = $st->fetch();
if (!$ch) { http_response_code(404); exit('not found'); }

$isAdmin = in_array(($u['role'] ?? ''), ['admin', 'moderator'], true) || !empty($u['is_admin']);
if ((int)($ch['user_id'] ?? $ch['owner_id'] ?? 0) !== (int)$u['id'] && !$isAdmin) {
  http_response_code(403); exit('forbidden');
}

$payload = [
  'exported_at' => date('c'),
  'engine' => 'StreamLive',
  'version' => 29,
  'format' => 'channel_backup_v1',
  'channel' => $ch,
  'sources' => [],
  'schedule' => [],
  'videos' => [],
];

$sourceIds = [];
if (!empty($ch['default_source_id'])) $sourceIds[] = (int)$ch['default_source_id'];

try {
  $s = db()->prepare('SELECT * FROM schedule WHERE channel_id = ? ORDER BY day_of_week, start_time');
  $s->execute([$id]);
  $payload['schedule'] = $s->fetchAll() ?: [];
  foreach ($payload['schedule'] as $row) {
    if (!empty($row['source_id'])) $sourceIds[] = (int)$row['source_id'];
  }
} catch (Throwable $e) {}

$sourceIds = array_values(array_unique(array_filter($sourceIds)));
if ($sourceIds) {
  try {
    $in = implode(',', array_fill(0, count($sourceIds), '?'));
    $s = db()->prepare("SELECT * FROM sources WHERE id IN ($in)");
    $s->execute($sourceIds);
    $payload['sources'] = $s->fetchAll() ?: [];
  } catch (Throwable $e) {}
}
// также все источники пользователя (чтобы бэкап был полнее)
try {
  $s = db()->prepare('SELECT * FROM sources WHERE created_by = ? ORDER BY id');
  $s->execute([(int)$ch['user_id']]);
  $mine = $s->fetchAll() ?: [];
  $have = array_column($payload['sources'], 'id');
  foreach ($mine as $m) {
    if (!in_array($m['id'], $have, true)) $payload['sources'][] = $m;
  }
} catch (Throwable $e) {}

try {
  $s = db()->prepare('SELECT id, slug, title, description, tags, source_url, platform, embed_url, thumbnail_url, status, views_count, created_at, premiere_at, is_premiere FROM videos WHERE channel_id = ? ORDER BY id');
  $s->execute([$id]);
  $payload['videos'] = $s->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $s = db()->prepare('SELECT id, slug, title, description, tags, source_url, platform, embed_url, thumbnail_url, status, views_count, created_at FROM videos WHERE channel_id = ? ORDER BY id');
    $s->execute([$id]);
    $payload['videos'] = $s->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}

if (isset($payload['channel']['stream_key'])) unset($payload['channel']['stream_key']);

$fname = 'channel_' . preg_replace('/[^a-zA-Z0-9_\-]+/', '_', (string)($ch['slug'] ?? $ch['id'])) . '_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
