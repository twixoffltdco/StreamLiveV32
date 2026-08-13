<?php
/** Роли канала: owner / admin / editor (как brand_members) */
function channel_staff_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS channel_staff (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        channel_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        role ENUM('admin','editor') NOT NULL DEFAULT 'editor',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ch_user (channel_id, user_id),
        KEY idx_user (user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function channel_staff_role(int $channelId, int $userId): ?string {
  if ($channelId <= 0 || $userId <= 0) return null;
  channel_staff_ensure();
  try {
    $st = db()->prepare('SELECT owner_id FROM channels WHERE id=?');
    $st->execute([$channelId]);
    $owner = (int)$st->fetchColumn();
    if ($owner === $userId) return 'owner';
  } catch (Throwable $e) {}
  try {
    $st = db()->prepare('SELECT role FROM channel_staff WHERE channel_id=? AND user_id=?');
    $st->execute([$channelId, $userId]);
    $r = $st->fetchColumn();
    return $r ? (string)$r : null;
  } catch (Throwable $e) {
    return null;
  }
}

function channel_staff_can_manage(int $channelId, int $userId): bool {
  $r = channel_staff_role($channelId, $userId);
  return in_array($r, ['owner', 'admin'], true);
}

function channel_staff_can_edit_content(int $channelId, int $userId): bool {
  $r = channel_staff_role($channelId, $userId);
  return in_array($r, ['owner', 'admin', 'editor'], true);
}

function channel_staff_list(int $channelId): array {
  channel_staff_ensure();
  try {
    $st = db()->prepare(
      'SELECT cs.*, u.username FROM channel_staff cs JOIN users u ON u.id=cs.user_id WHERE cs.channel_id=? ORDER BY cs.role, u.username'
    );
    $st->execute([$channelId]);
    return $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function channel_staff_add(int $channelId, int $actorId, string $username, string $role = 'editor'): array {
  channel_staff_ensure();
  if (!channel_staff_can_manage($channelId, $actorId)) {
    return ['ok' => false, 'error' => 'Нет прав'];
  }
  $role = in_array($role, ['admin', 'editor'], true) ? $role : 'editor';
  $username = trim($username);
  if ($username === '') return ['ok' => false, 'error' => 'Укажите ник'];
  try {
    $st = db()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $st->execute([$username]);
    $uid = (int)$st->fetchColumn();
    if ($uid <= 0) return ['ok' => false, 'error' => 'Пользователь не найден'];
    if ($uid === $actorId) return ['ok' => false, 'error' => 'Нельзя добавить себя'];
    // не owner
    $ow = db()->prepare('SELECT owner_id FROM channels WHERE id=?');
    $ow->execute([$channelId]);
    if ((int)$ow->fetchColumn() === $uid) return ['ok' => false, 'error' => 'Это владелец канала'];
    db()->prepare(
      "INSERT INTO channel_staff (channel_id, user_id, role) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE role=VALUES(role)"
    )->execute([$channelId, $uid, $role]);
    return ['ok' => true, 'error' => ''];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function channel_staff_remove(int $channelId, int $actorId, int $memberId): array {
  if (!channel_staff_can_manage($channelId, $actorId)) {
    return ['ok' => false, 'error' => 'Нет прав'];
  }
  try {
    db()->prepare('DELETE FROM channel_staff WHERE channel_id=? AND user_id=?')->execute([$channelId, $memberId]);
    return ['ok' => true, 'error' => ''];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}
