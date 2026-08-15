<?php
/**
 * «Смотрит на телефоне» для Flex World.
 * Пока юзер на сайте смотрит видео/канал — в мире у персонажа phone_on + title.
 */
function flex_watching_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS flex_watching (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        title VARCHAR(160) NOT NULL DEFAULT '',
        url VARCHAR(500) NOT NULL DEFAULT '',
        source VARCHAR(32) NOT NULL DEFAULT 'video',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function flex_watching_set(int $userId, string $title, string $url = '', string $source = 'video'): void {
  if ($userId <= 0) return;
  flex_watching_ensure();
  $title = mb_substr(trim($title), 0, 160);
  $url = mb_substr(trim($url), 0, 500);
  if ($title === '') $title = 'контент StreamLive';
  try {
    db()->prepare(
      "INSERT INTO flex_watching (user_id, title, url, source, updated_at) VALUES (?,?,?,?,NOW())
       ON DUPLICATE KEY UPDATE title=VALUES(title), url=VALUES(url), source=VALUES(source), updated_at=NOW()"
    )->execute([$userId, $title, $url, mb_substr($source, 0, 32)]);
  } catch (Throwable $e) {}
}

function flex_watching_clear(int $userId): void {
  if ($userId <= 0) return;
  try {
    db()->prepare('DELETE FROM flex_watching WHERE user_id=?')->execute([$userId]);
  } catch (Throwable $e) {}
}

/** Активный просмотр за последние 3 минуты */
function flex_watching_get(int $userId): ?array {
  if ($userId <= 0) return null;
  flex_watching_ensure();
  try {
    $st = db()->prepare(
      'SELECT title, url, source, updated_at FROM flex_watching
       WHERE user_id=? AND updated_at > (NOW() - INTERVAL 3 MINUTE) LIMIT 1'
    );
    $st->execute([$userId]);
    $r = $st->fetch();
    return $r ?: null;
  } catch (Throwable $e) {
    return null;
  }
}
