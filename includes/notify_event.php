<?php
/** Событийные уведомления без постоянного poll */

function notify_ensure_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS notifications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      channel_id INT DEFAULT NULL,
      type VARCHAR(50) NOT NULL,
      message VARCHAR(500) NOT NULL,
      link VARCHAR(500) DEFAULT NULL,
      is_read TINYINT(1) DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY idx_user_read (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}
}

function notify_event(int $userId, string $type, string $message, ?string $link = null, ?int $channelId = null): void {
  if ($userId <= 0) return;
  notify_ensure_table();
  $message = mb_substr(trim($message), 0, 500);
  if ($message === '') return;
  try {
    db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message, link) VALUES (?,?,?,?,?)')
      ->execute([$userId, $channelId, mb_substr($type, 0, 50), $message, $link ? mb_substr($link, 0, 500) : null]);
  } catch (Throwable $e) {}
}

/** Уведомить подписчиков канала / избранное */
function notify_channel_fans(int $channelId, string $type, string $message, ?string $link = null): void {
  $uids = [];
  try {
    $st = db()->prepare('SELECT user_id FROM favorites WHERE channel_id = ?');
    $st->execute([$channelId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $uids[(int)$id] = true;
  } catch (Throwable $e) {}
  try {
    $st = db()->prepare('SELECT user_id FROM channel_subscriptions WHERE channel_id = ?');
    $st->execute([$channelId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $uids[(int)$id] = true;
  } catch (Throwable $e) {}
  foreach (array_keys($uids) as $uid) {
    notify_event((int)$uid, $type, $message, $link, $channelId);
  }
}
