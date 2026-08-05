<?php
/**
 * Уведомления StreamLive — единая шина событий.
 * Новые фичи: notify_event('my_event', [...]) — без переписывания пуша.
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
    try { db()->exec('ALTER TABLE notifications ADD COLUMN link VARCHAR(500) DEFAULT NULL'); } catch (Throwable $e) {}
    try {
      db()->exec(
        "CREATE TABLE IF NOT EXISTS schedule_notify_log (
          id INT AUTO_INCREMENT PRIMARY KEY,
          schedule_id INT NOT NULL,
          kind VARCHAR(20) NOT NULL,
          slot_date DATE NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_slot (schedule_id, kind, slot_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
      );
    } catch (Throwable $e) {}
  }

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

  function notify_users(array $userIds, string $type, string $message, ?string $link = null, ?int $channelId = null, ?int $exceptUserId = null): void {
    $seen = [];
    foreach ($userIds as $uid) {
      $uid = (int)$uid;
      if ($uid <= 0 || isset($seen[$uid])) continue;
      $seen[$uid] = true;
      notify_user($uid, $type, $message, $link, $channelId, $exceptUserId);
    }
  }

  /** Кто добавил канал в избранное */
  function notify_channel_favoriters(int $channelId): array {
    $ids = [];
    try {
      $st = db()->prepare('SELECT user_id FROM favorites WHERE channel_id = ?');
      $st->execute([$channelId]);
      while ($r = $st->fetch()) $ids[] = (int)$r['user_id'];
    } catch (Throwable $e) {}
    try {
      $st = db()->prepare('SELECT user_id FROM channel_favorites WHERE channel_id = ?');
      $st->execute([$channelId]);
      while ($r = $st->fetch()) $ids[] = (int)$r['user_id'];
    } catch (Throwable $e) {}
    return array_values(array_unique(array_filter($ids)));
  }

  /**
   * Шина событий — новые функции платформы зовут notify_event(), пуш подхватывает сам.
   * payload: message, link?, channel_id?, user_ids? (явный список), except_user_id?
   * Если user_ids нет и есть channel_id — шлём всем из избранного канала.
   */
  function notify_event(string $event, array $payload = []): void {
    $event = mb_substr(preg_replace('/[^a-z0-9_]/i', '', $event) ?: 'system', 0, 50);
    $message = (string)($payload['message'] ?? '');
    $link = isset($payload['link']) ? (string)$payload['link'] : null;
    $channelId = isset($payload['channel_id']) ? (int)$payload['channel_id'] : null;
    $except = isset($payload['except_user_id']) ? (int)$payload['except_user_id'] : null;
    $userIds = $payload['user_ids'] ?? null;

    if ($message === '') return;

    if (is_array($userIds) && $userIds) {
      notify_users($userIds, $event, $message, $link, $channelId, $except);
      return;
    }
    if ($channelId) {
      notify_users(notify_channel_favoriters($channelId), $event, $message, $link, $channelId, $except);
      return;
    }
  }

  /** Всем пользователям (новые фичи / объявления). Осторожно на больших базах. */
  function notify_platform(string $type, string $message, ?string $link = null, int $limit = 5000): void {
    $ids = [];
    try {
      $st = db()->query('SELECT id FROM users WHERE COALESCE(is_banned,0) = 0 ORDER BY id DESC LIMIT ' . (int)$limit);
      while ($r = $st->fetch()) $ids[] = (int)$r['id'];
    } catch (Throwable $e) {}
    notify_users($ids, $type, $message, $link, null, null);
  }
}

/**
 * Расписание: за 1 мин до старта + в момент старта эфира.
 * Вызывается из poll (раз в ~30 сек на весь сайт через file lock).
 */
if (!function_exists('schedule_notify_tick')) {
  function schedule_notify_tick(): void {
    if (!function_exists('notify_ensure_schema')) return;
    notify_ensure_schema();

    $lockFile = sys_get_temp_dir() . '/sl_sched_notify.lock';
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) return;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
      fclose($fp);
      return;
    }

    try {
      $now = new DateTime('now'); // Europe/Moscow
      $dow = (int)$now->format('N') - 1; // 0=Пн
      $today = $now->format('Y-m-d');
      $tNow = $now->format('H:i:s');
      $tPlus1 = (clone $now)->modify('+1 minute')->format('H:i:s');
      // окно «сейчас началось»: start_time в [now-90s, now+15s]
      $tMinus = (clone $now)->modify('-90 seconds')->format('H:i:s');

      // Слоты, которые стартуют через ~1 минуту (та же минута что tPlus1)
      $startMinute = substr($tPlus1, 0, 5) . ':00';
      $startNowMinute = substr($tNow, 0, 5) . ':00';

      $rows = [];
      try {
        $st = db()->prepare(
          "SELECT sch.id, sch.channel_id, sch.start_time, sch.program_title, sch.day_of_week,
                  c.title AS channel_title, c.slug AS channel_slug, s.name AS source_name
           FROM schedule sch
           JOIN channels c ON c.id = sch.channel_id
           JOIN sources s ON s.id = sch.source_id
           WHERE sch.day_of_week = ?
             AND (
               sch.start_time = ?
               OR sch.start_time = ?
             )"
        );
        $st->execute([$dow, $startMinute, $startNowMinute]);
        $rows = $st->fetchAll() ?: [];
      } catch (Throwable $e) {
        $rows = [];
      }

      foreach ($rows as $row) {
        $sid = (int)$row['id'];
        $chId = (int)$row['channel_id'];
        $title = trim((string)($row['program_title'] ?: $row['source_name'] ?: 'Эфир'));
        $chName = (string)($row['channel_title'] ?? 'Канал');
        $slug = (string)($row['channel_slug'] ?? '');
        $link = $slug !== '' ? '/channel?slug=' . rawurlencode($slug) : '/channel.php?id=' . $chId;
        $startHm = substr((string)$row['start_time'], 0, 5);

        $isUpcoming = (substr((string)$row['start_time'], 0, 5) === substr($startMinute, 0, 5)
          && substr($startMinute, 0, 5) !== substr($startNowMinute, 0, 5));
        $isStart = (substr((string)$row['start_time'], 0, 5) === substr($startNowMinute, 0, 5));

        if ($isUpcoming) {
          $kind = 'soon';
          if (schedule_notify_already($sid, $kind, $today)) continue;
          $msg = 'Через 1 мин на «' . $chName . '»: «' . mb_substr($title, 0, 80) . '» (с ' . $startHm . ' МСК)';
          notify_users(notify_channel_favoriters($chId), 'schedule_soon', $msg, $link, $chId);
          schedule_notify_mark($sid, $kind, $today);
        }
        if ($isStart) {
          $kind = 'started';
          if (schedule_notify_already($sid, $kind, $today)) continue;
          $msg = 'Начался эфир на «' . $chName . '»: «' . mb_substr($title, 0, 80) . '» с ' . $startHm . ' МСК. Приятного просмотра!';
          notify_users(notify_channel_favoriters($chId), 'schedule_start', $msg, $link, $chId);
          schedule_notify_mark($sid, $kind, $today);
        }
      }
    } catch (Throwable $e) {
      // тихо
    }

    flock($fp, LOCK_UN);
    fclose($fp);
  }

  function schedule_notify_already(int $scheduleId, string $kind, string $date): bool {
    try {
      $st = db()->prepare('SELECT id FROM schedule_notify_log WHERE schedule_id = ? AND kind = ? AND slot_date = ?');
      $st->execute([$scheduleId, $kind, $date]);
      return (bool)$st->fetch();
    } catch (Throwable $e) {
      return false;
    }
  }

  function schedule_notify_mark(int $scheduleId, string $kind, string $date): void {
    try {
      db()->prepare('INSERT IGNORE INTO schedule_notify_log (schedule_id, kind, slot_date) VALUES (?, ?, ?)')
        ->execute([$scheduleId, $kind, $date]);
    } catch (Throwable $e) {}
  }
}
