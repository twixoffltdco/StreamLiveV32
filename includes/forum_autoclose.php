<?php
/**
 * Автозакрытие тем форума (только система, открыть обратно нельзя).
 * - Нет активности 7 дней → is_locked + auto_closed
 * - Была активность, потом тишина 16 дней → is_locked + auto_closed
 */
function forum_autoclose_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec('ALTER TABLE forum_threads ADD COLUMN auto_closed TINYINT(1) NOT NULL DEFAULT 0');
  } catch (Throwable $e) {}
  try {
    db()->exec('ALTER TABLE forum_threads ADD COLUMN last_activity_at DATETIME NULL');
  } catch (Throwable $e) {}
  try {
    db()->exec('UPDATE forum_threads SET last_activity_at = COALESCE(updated_at, created_at, NOW()) WHERE last_activity_at IS NULL');
  } catch (Throwable $e) {}
}

function forum_autoclose_touch(int $threadId): void {
  forum_autoclose_ensure();
  try {
    db()->prepare('UPDATE forum_threads SET last_activity_at = NOW() WHERE id = ? AND auto_closed = 0')
      ->execute([$threadId]);
  } catch (Throwable $e) {}
}

function forum_autoclose_run(int $limit = 80): int {
  forum_autoclose_ensure();
  $n = 0;
  try {
    // 7 дней полной тишины: last_activity ≈ created (нет ответов) ИЛИ replies=0
    $sql7 = "UPDATE forum_threads SET is_locked = 1, auto_closed = 1
             WHERE auto_closed = 0 AND is_deleted = 0 AND is_locked = 0
               AND COALESCE(last_activity_at, updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND (COALESCE(replies_count, posts_count, 0) <= 1 OR last_activity_at IS NULL OR last_activity_at <= COALESCE(created_at, last_activity_at))
             LIMIT " . (int)$limit;
    // Simpler rule matching user intent:
    // no activity 7d → close; any last activity older than 16d → close
    $st = db()->prepare(
      "SELECT id, COALESCE(replies_count, 0) AS rc,
              COALESCE(last_activity_at, updated_at, created_at) AS la
       FROM forum_threads
       WHERE auto_closed = 0 AND is_deleted = 0 AND COALESCE(is_locked,0) = 0
       ORDER BY id ASC LIMIT ?"
    );
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll() ?: [];
    $now = time();
    foreach ($rows as $r) {
      $la = strtotime((string)$r['la']) ?: 0;
      if ($la <= 0) continue;
      $days = ($now - $la) / 86400;
      $rc = (int)$r['rc'];
      $close = false;
      if ($rc <= 0 && $days >= 7) $close = true;          // нет активности → 7 дней
      elseif ($rc > 0 && $days >= 16) $close = true;       // была, потом затихло → 16 дней
      elseif ($days >= 16) $close = true;                  // общий потолок 16
      if ($close) {
        db()->prepare('UPDATE forum_threads SET is_locked = 1, auto_closed = 1 WHERE id = ?')
          ->execute([(int)$r['id']]);
        $n++;
      }
    }
  } catch (Throwable $e) {}
  return $n;
}

/** Запрет открытия автозакрытых */
function forum_autoclose_block_unlock(array $thread): bool {
  return !empty($thread['auto_closed']);
}
