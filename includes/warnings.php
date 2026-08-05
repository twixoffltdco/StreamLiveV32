<?php
/**
 * Система предупреждений.
 * 30 предупреждений за 7 дней → автоблокировка на 7 дней.
 */
declare(strict_types=1);

function warnings_ensure_table(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS user_warnings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        moderator_id INT UNSIGNED NOT NULL DEFAULT 0,
        reason VARCHAR(500) NOT NULL DEFAULT '',
        points INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {
    error_log('[warnings] ' . $e->getMessage());
  }
}

function warnings_count_week(int $userId): int {
  warnings_ensure_table();
  try {
    $st = db()->prepare(
      "SELECT COALESCE(SUM(points),0) FROM user_warnings
       WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY)"
    );
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

function warnings_add(int $userId, int $moderatorId, string $reason, int $points = 1): array {
  warnings_ensure_table();
  if ($userId <= 0) return ['ok' => false, 'error' => 'bad user'];
  $points = max(1, min(10, $points));
  $reason = mb_substr(trim($reason), 0, 500) ?: 'Нарушение правил';
  try {
    $st = db()->prepare(
      "INSERT INTO user_warnings (user_id, moderator_id, reason, points) VALUES (?,?,?,?)"
    );
    $st->execute([$userId, $moderatorId, $reason, $points]);
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
  $week = warnings_count_week($userId);
  $banned = false;
  if ($week >= 30) {
    $banned = warnings_auto_ban($userId, $week);
  }
  return ['ok' => true, 'week_points' => $week, 'banned' => $banned];
}

function warnings_auto_ban(int $userId, int $weekPoints): bool {
  try {
    // users.is_banned / banned_until / ban_reason — мягко
    $cols = [];
    try {
      $dbName = db()->query('SELECT DATABASE()')->fetchColumn();
      $st = db()->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='users'");
      $st->execute([$dbName]);
      $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {}
    $until = date('Y-m-d H:i:s', time() + 7 * 86400);
    $reason = "Автоблок: {$weekPoints} предупреждений за 7 дней";
    if (in_array('banned_until', $cols, true)) {
      db()->prepare("UPDATE users SET banned_until = ? WHERE id = ?")->execute([$until, $userId]);
    }
    if (in_array('ban_reason', $cols, true)) {
      db()->prepare("UPDATE users SET ban_reason = ? WHERE id = ?")->execute([$reason, $userId]);
    }
    if (in_array('is_banned', $cols, true)) {
      db()->prepare("UPDATE users SET is_banned = 1 WHERE id = ?")->execute([$userId]);
    }
    if (in_array('status', $cols, true)) {
      try {
        db()->prepare("UPDATE users SET status = 'banned' WHERE id = ?")->execute([$userId]);
      } catch (Throwable $e) {}
    }
    return true;
  } catch (Throwable $e) {
    error_log('[warnings ban] ' . $e->getMessage());
    return false;
  }
}

function warnings_list(int $userId, int $limit = 50): array {
  warnings_ensure_table();
  try {
    $st = db()->prepare(
      "SELECT w.*, u.username AS mod_name
       FROM user_warnings w
       LEFT JOIN users u ON u.id = w.moderator_id
       WHERE w.user_id = ?
       ORDER BY w.id DESC LIMIT ?"
    );
    $st->bindValue(1, $userId, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    return [];
  }
}
