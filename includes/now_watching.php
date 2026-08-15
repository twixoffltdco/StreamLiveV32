<?php
function now_watching_ensure(): void {
  static $d = false; if ($d) return; $d = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS now_watching (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        target_type VARCHAR(20) NOT NULL DEFAULT 'video',
        target_id INT UNSIGNED NOT NULL DEFAULT 0,
        title VARCHAR(255) NULL,
        url VARCHAR(500) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_updated (updated_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function now_watching_set(int $userId, string $type, int $id, string $title = '', string $url = ''): void {
  if ($userId <= 0) return;
  now_watching_ensure();
  try {
    db()->prepare(
      "INSERT INTO now_watching (user_id, target_type, target_id, title, url, updated_at)
       VALUES (?,?,?,?,?,NOW())
       ON DUPLICATE KEY UPDATE target_type=VALUES(target_type), target_id=VALUES(target_id),
         title=VALUES(title), url=VALUES(url), updated_at=NOW()"
    )->execute([$userId, $type, $id, mb_substr($title,0,255), mb_substr($url,0,500)]);
  } catch (Throwable $e) {}
}

function now_watching_list(int $limit = 30): array {
  now_watching_ensure();
  try {
    $st = db()->query(
      "SELECT w.*, u.username FROM now_watching w
       JOIN users u ON u.id = w.user_id
       WHERE w.updated_at > (NOW() - INTERVAL 15 MINUTE)
       ORDER BY w.updated_at DESC LIMIT " . (int)$limit
    );
    return $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    return [];
  }
}
