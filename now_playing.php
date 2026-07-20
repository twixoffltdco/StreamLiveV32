<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

$channelId = (int)($_GET['channel_id'] ?? 0);

$stmt = db()->prepare("SELECT * FROM channels WHERE id = ? AND status = 'approved'");
$stmt->execute([$channelId]);
$channel = $stmt->fetch();

if (!$channel) {
  echo json_encode(['ok' => false, 'error' => 'Канал не найден']);
  exit;
}

$source = resolve_active_source($channel);
$next = find_next_program($channelId);
$days = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

echo json_encode([
  'ok' => true,
  'is_paused' => (bool)($channel['is_broadcast_paused'] ?? false),
  'source' => $source ? [
    'id' => (int)$source['id'],
    'schedule_id' => isset($source['schedule_id']) ? (int)$source['schedule_id'] : null,
    'type' => $source['type'],
    'url' => $source['url'],
    'program_title' => $source['program_title'] ?? null,
    // Для mp4 — точка отсчёта "виртуального прямого эфира": все зрители синхронизируются
    // на одну и ту же секунду видео по этому времени, как настоящая трансляция, а не каждый
    // смотрит с нуля.
    'sync_epoch' => ($source['type'] === 'mp4' && !empty($source['created_at'])) ? strtotime($source['created_at']) : null,
  ] : null,
  'next' => $next ? [
    'day' => $days[(int)$next['day_of_week']],
    'start_time' => substr($next['start_time'], 0, 5),
    'program_title' => $next['program_title'] ?: $next['source_name'],
  ] : null,
]);
