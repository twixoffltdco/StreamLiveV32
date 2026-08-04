<?php
/**
 * Единая точка уведомлений StreamLive.
 * Пишет в notifications + отдаёт через /api_notifications_poll для браузерного push.
 */
if (!function_exists('notify_ensure_schema')) {
  function notify_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
      db()->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          channel_id INT DEFAULT NULL,
          type VARCHAR(50) NOT NULL,
          message VARCHAR(500) NOT NULL,
          link VARCHAR(500) DEFAULT NULL,
          is_read TINYINT(1) DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_user_read (user_id, is_read),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
      );
    } catch (Throwable $e) {}
    try {
      db()->exec('ALTER TABLE notifications ADD COLUMN link VARCHAR(500) DEFAULT NULL');
    } catch (Throwable $e) {}
  }

  /**
   * @param int $userId получатель
   * @param string $type message|video_new|channel_new|forum_reply|system|...
   * @param string $message текст
   * @param string|null $link URL для клика
   * @param int|null $channelId
   * @param int|null $exceptUserId не слать, если совпадает (себе)
   */
  function notify_user(int $userId, string $type, string $message, ?string $link = null, ?int $channelId = null, ?int $exceptUserId = null): void {
    if ($userId <= 0) return;
    if ($exceptUserId !== null && $userId === $exceptUserId) return;
    $message = mb_substr(trim($message), 0, 500);
    if ($message === '') return;
    $type = mb_substr(preg_replace('/[^a-z0-9_]/i', '', $type) ?: 'system', 0, 50);
    notify_ensure_schema();
    try {
      db()->prepare(
        'INSERT INTO notifications (user_id, channel_id, type, message, link, is_read) VALUES (?, ?, ?, ?, ?, 0)'
      )->execute([$userId, $channelId, $type, $message, $link]);
    } catch (Throwable $e) {
      try {
        db()->prepare(
          'INSERT INTO notifications (user_id, channel_id, type, message, is_read) VALUES (?, ?, ?, ?, 0)'
        )->execute([$userId, $channelId, $type, $message]);
      } catch (Throwable $e2) {}
    }
  }

  /** Нескольким пользователям */
  function notify_users(array $userIds, string $type, string $message, ?string $link = null, ?int $channelId = null, ?int $exceptUserId = null): void {
    $seen = [];
    foreach ($userIds as $uid) {
      $uid = (int)$uid;
      if ($uid <= 0 || isset($seen[$uid])) continue;
      $seen[$uid] = true;
      notify_user($uid, $type, $message, $link, $channelId, $exceptUserId);
    }
  }

  /** Подписчикам канала (favorites на видео канала + владелец не шлём) */
  function notify_channel_audience(int $channelId, string $type, string $message, ?string $link, int $exceptUserId): void {
    $ids = [];
    try {
      $st = db()->prepare('SELECT DISTINCT vf.user_id FROM video_favorites vf JOIN videos v ON v.id = vf.video_id WHERE v.channel_id = ?');
      $st->execute([$channelId]);
      while ($row = $st->fetch()) $ids[] = (int)$row['user_id'];
    } catch (Throwable $e) {}
    try {
      $st = db()->prepare('SELECT DISTINCT user_id FROM channel_subscriptions WHERE channel_id = ?');
      $st->execute([$channelId]);
      while ($row = $st->fetch()) $ids[] = (int)$row['user_id'];
    } catch (Throwable $e) {}
    // кто добавил канал в избранное через notifications watch — optional table
    notify_users($ids, $type, $message, $link, $channelId, $exceptUserId);
  }
}
