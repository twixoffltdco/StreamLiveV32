<?php
/**
 * JSON-поллинг комментариев канала (ТВ/радио).
 * GET: channel_id, after (id последнего известного комментария, 0 = последние N)
 */
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$channelId = (int)($_GET['channel_id'] ?? 0);
$after = (int)($_GET['after'] ?? 0);

if ($channelId <= 0) {
  echo json_encode(['ok' => false, 'comments' => []]);
  exit;
}

try {
  // канал должен существовать
  $st = db()->prepare('SELECT id FROM channels WHERE id = ? LIMIT 1');
  $st->execute([$channelId]);
  if (!$st->fetch()) {
    echo json_encode(['ok' => false, 'comments' => []]);
    exit;
  }

  if ($after > 0) {
    $stmt = db()->prepare(
      "SELECT cm.id, cm.message, cm.created_at, u.username, u.is_verified
       FROM comments cm
       JOIN users u ON u.id = cm.user_id
       WHERE cm.channel_id = ? AND cm.is_deleted = 0 AND cm.id > ?
       ORDER BY cm.id ASC
       LIMIT 50"
    );
    $stmt->execute([$channelId, $after]);
  } else {
    // первичная подгрузка / полный список (новые сверху для отображения)
    $stmt = db()->prepare(
      "SELECT cm.id, cm.message, cm.created_at, u.username, u.is_verified
       FROM comments cm
       JOIN users u ON u.id = cm.user_id
       WHERE cm.channel_id = ? AND cm.is_deleted = 0
       ORDER BY cm.id DESC
       LIMIT 100"
    );
    $stmt->execute([$channelId]);
  }

  $rows = $stmt->fetchAll();
  $comments = [];
  foreach ($rows as $r) {
    $comments[] = [
      'id' => (int)$r['id'],
      'username' => (string)$r['username'],
      'message' => (string)$r['message'],
      'created_at' => (string)$r['created_at'],
      'is_verified' => !empty($r['is_verified']),
    ];
  }

  // для after=0 отдаём в хронологическом порядке (старые → новые) удобнее для append
  if ($after <= 0) {
    $comments = array_reverse($comments);
  }

  $maxId = 0;
  foreach ($comments as $c) {
    if ($c['id'] > $maxId) $maxId = $c['id'];
  }

  echo json_encode(['ok' => true, 'comments' => $comments, 'max_id' => $maxId]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'comments' => []]);
}
