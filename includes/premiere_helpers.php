<?php
/** Премьеры YouTube-style: колонки, apply, one-time, countdown */

function premiere_ensure_columns(?PDO $pdo = null): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    $pdo = $pdo ?: db();
  } catch (Throwable $e) {
    return;
  }
  foreach ([
    "ALTER TABLE videos ADD COLUMN premiere_at DATETIME NULL DEFAULT NULL",
    "ALTER TABLE videos ADD COLUMN premiere_end_at DATETIME NULL DEFAULT NULL",
    "ALTER TABLE videos ADD COLUMN is_premiere TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE videos ADD COLUMN premiere_used TINYINT(1) NOT NULL DEFAULT 0",
  ] as $q) {
    try { $pdo->exec($q); } catch (Throwable $e) {}
  }
}

function premiere_channel_is_tv_radio(int $channelId): bool {
  if ($channelId <= 0) return false;
  try {
    $st = db()->prepare('SELECT type FROM channels WHERE id = ?');
    $st->execute([$channelId]);
    $t = strtolower((string)($st->fetchColumn() ?: ''));
    return in_array($t, ['tv', 'radio'], true);
  } catch (Throwable $e) {
    return false;
  }
}

function premiere_can_schedule(array $video, int $channelId = 0): array {
  premiere_ensure_columns();
  $cid = $channelId > 0 ? $channelId : (int)($video['channel_id'] ?? 0);
  if (premiere_channel_is_tv_radio($cid)) {
    return ['ok' => true, 'reason' => ''];
  }
  if (!empty($video['premiere_used'])) {
    return ['ok' => false, 'reason' => 'Премьеру на это видео уже использовали (1 раз как YouTube).'];
  }
  $end = 0;
  if (!empty($video['premiere_end_at'])) {
    $end = (int)strtotime((string)$video['premiere_end_at']);
  } elseif (!empty($video['premiere_at'])) {
    $end = (int)strtotime((string)$video['premiere_at']) + 7200;
  }
  if (!empty($video['is_premiere']) && $end > 0 && $end <= time()) {
    return ['ok' => false, 'reason' => 'Премьера уже прошла.'];
  }
  return ['ok' => true, 'reason' => ''];
}

function premiere_parse_local(string $s): int {
  $s = trim(str_replace('T', ' ', $s));
  if ($s === '') return 0;
  $ts = strtotime($s);
  return $ts ?: 0;
}

function premiere_apply(int $videoId, int $channelId, string $startAt, string $endAt = ''): array {
  premiere_ensure_columns();
  if ($videoId <= 0) return ['ok' => false, 'error' => 'bad id'];
  try {
    $st = db()->prepare('SELECT * FROM videos WHERE id = ?');
    $st->execute([$videoId]);
    $video = $st->fetch() ?: [];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
  if (!$video) return ['ok' => false, 'error' => 'video not found'];
  $cid = $channelId > 0 ? $channelId : (int)($video['channel_id'] ?? 0);
  $can = premiere_can_schedule($video, $cid);
  if (!$can['ok']) return ['ok' => false, 'error' => $can['reason']];

  $ts = premiere_parse_local($startAt);
  if ($ts <= 0) return ['ok' => false, 'error' => 'Некорректное время начала'];
  $tsEnd = $endAt !== '' ? premiere_parse_local($endAt) : ($ts + 7200);
  if ($tsEnd <= $ts) $tsEnd = $ts + 7200;

  try {
    db()->prepare(
      "UPDATE videos SET is_premiere = 1, premiere_at = ?, premiere_end_at = ?, premiere_used = 0, status = 'published' WHERE id = ?"
    )->execute([
      date('Y-m-d H:i:s', $ts),
      date('Y-m-d H:i:s', $tsEnd),
      $videoId,
    ]);
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
  return ['ok' => true, 'error' => '', 'premiere_at' => date('Y-m-d H:i:s', $ts), 'premiere_end_at' => date('Y-m-d H:i:s', $tsEnd)];
}

function premiere_mark_used_if_ended(): void {
  premiere_ensure_columns();
  try {
    db()->exec(
      "UPDATE videos SET premiere_used = 1, is_premiere = 0
       WHERE is_premiere = 1 AND premiere_end_at IS NOT NULL AND premiere_end_at < NOW()"
    );
  } catch (Throwable $e) {}
}

/** Состояние для UI */
function premiere_state(array $video): array {
  premiere_ensure_columns();
  $at = !empty($video['premiere_at']) ? (int)strtotime((string)$video['premiere_at']) : 0;
  $end = !empty($video['premiere_end_at']) ? (int)strtotime((string)$video['premiere_end_at']) : 0;
  if ($at > 0 && $end <= 0) $end = $at + 7200;
  $now = time();
  $is = !empty($video['is_premiere']) || ($at > $now);
  if ($at <= 0) {
    return ['wait' => false, 'live' => false, 'ended' => false, 'at' => 0, 'end' => 0];
  }
  if ($now < $at) {
    return ['wait' => true, 'live' => false, 'ended' => false, 'at' => $at, 'end' => $end];
  }
  if ($end > 0 && $now < $end) {
    return ['wait' => false, 'live' => true, 'ended' => false, 'at' => $at, 'end' => $end];
  }
  return ['wait' => false, 'live' => false, 'ended' => true, 'at' => $at, 'end' => $end];
}
