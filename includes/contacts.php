<?php
/**
 * Контакты / ЧС / жалобы — как в Telegram/VK.
 * Все CREATE/ALTER в try/catch — без 500 на shared-хостинге.
 */

function contacts_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS user_contacts (
        user_id INT NOT NULL,
        contact_user_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, contact_user_id),
        KEY idx_contact (contact_user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) { /* no CREATE rights */ }
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS user_blocks (
        blocker_id INT NOT NULL,
        blocked_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (blocker_id, blocked_id),
        KEY idx_blocked (blocked_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS user_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT NOT NULL,
        reported_id INT NOT NULL,
        reason ENUM('spam','abuse','scam','other') NOT NULL DEFAULT 'spam',
        severity ENUM('weak','medium','strong') NOT NULL DEFAULT 'medium',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_reporter_reported_reason (reporter_id, reported_id, reason),
        KEY idx_reported (reported_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}
  try {
    if (function_exists('table_column_exists') && !table_column_exists('users', 'banned_until')) {
      db()->exec('ALTER TABLE users ADD COLUMN banned_until DATETIME DEFAULT NULL');
    }
  } catch (Throwable $e) {}
  try {
    if (function_exists('table_column_exists') && !table_column_exists('users', 'ban_reason')) {
      db()->exec('ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) DEFAULT NULL');
    }
  } catch (Throwable $e) {}
}

function contacts_duration_days(string $severity): int {
  switch ($severity) {
    case 'strong': return 365;   // год
    case 'medium': return 60;    // 2 месяца
    case 'weak':   return 14;    // 14 дней
    default:       return 30;    // 1 месяц по умолчанию
  }
}

/** Снять истёкший бан */
function contacts_maybe_unban(int $userId): void {
  if ($userId <= 0) return;
  contacts_ensure_schema();
  try {
    $stmt = db()->prepare('SELECT is_banned, banned_until, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u || empty($u['is_banned'])) return;
    $until = $u['banned_until'] ?? null;
    if ($until && strtotime((string)$until) <= time()) {
      db()->prepare('UPDATE users SET is_banned = 0, banned_until = NULL, ban_reason = NULL WHERE id = ?')
        ->execute([$userId]);
    }
  } catch (Throwable $e) {}
}

/**
 * Бан пользователя (не админов/модераторов).
 * $severity: weak|medium|strong
 */
function contacts_ban_user(int $userId, string $severity, string $reason): bool {
  if ($userId <= 0) return false;
  contacts_ensure_schema();
  try {
    $stmt = db()->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $role = (string)($stmt->fetchColumn() ?: 'user');
    if (in_array($role, ['admin', 'moderator'], true)) {
      return false; // админов/модов не баним автоматом
    }
    $days = contacts_duration_days($severity);
    $until = date('Y-m-d H:i:s', time() + $days * 86400);
    db()->prepare(
      'UPDATE users SET is_banned = 1, banned_until = ?, ban_reason = ? WHERE id = ?'
    )->execute([$until, mb_substr($reason, 0, 255), $userId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function contacts_is_contact(int $userId, int $contactId): bool {
  if ($userId <= 0 || $contactId <= 0 || $userId === $contactId) return false;
  contacts_ensure_schema();
  try {
    $stmt = db()->prepare('SELECT 1 FROM user_contacts WHERE user_id = ? AND contact_user_id = ? LIMIT 1');
    $stmt->execute([$userId, $contactId]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/** Взаимные контакты — оба добавили друг друга */
function contacts_are_mutual(int $a, int $b): bool {
  return contacts_is_contact($a, $b) && contacts_is_contact($b, $a);
}

function contacts_add(int $userId, int $contactId): bool {
  if ($userId <= 0 || $contactId <= 0 || $userId === $contactId) return false;
  contacts_ensure_schema();
  try {
    db()->prepare('INSERT IGNORE INTO user_contacts (user_id, contact_user_id) VALUES (?, ?)')
      ->execute([$userId, $contactId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function contacts_remove(int $userId, int $contactId): bool {
  if ($userId <= 0 || $contactId <= 0) return false;
  contacts_ensure_schema();
  try {
    db()->prepare('DELETE FROM user_contacts WHERE user_id = ? AND contact_user_id = ?')
      ->execute([$userId, $contactId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function contacts_is_blocked(int $blockerId, int $blockedId): bool {
  if ($blockerId <= 0 || $blockedId <= 0) return false;
  contacts_ensure_schema();
  try {
    $stmt = db()->prepare('SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1');
    $stmt->execute([$blockerId, $blockedId]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/** Есть блок в любую сторону */
function contacts_is_blocked_either(int $a, int $b): bool {
  return contacts_is_blocked($a, $b) || contacts_is_blocked($b, $a);
}

function contacts_block(int $blockerId, int $blockedId): bool {
  if ($blockerId <= 0 || $blockedId <= 0 || $blockerId === $blockedId) return false;
  contacts_ensure_schema();
  try {
    db()->prepare('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)')
      ->execute([$blockerId, $blockedId]);
    // при блоке убираем из контактов в обе стороны (как в TG)
    contacts_remove($blockerId, $blockedId);
    contacts_remove($blockedId, $blockerId);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function contacts_unblock(int $blockerId, int $blockedId): bool {
  if ($blockerId <= 0 || $blockedId <= 0) return false;
  contacts_ensure_schema();
  try {
    db()->prepare('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?')
      ->execute([$blockerId, $blockedId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Жалоба. При 10+ уникальных spam-жалобах — автобан medium (2 мес).
 * @return array{ok:bool,error?:string,auto_banned?:bool}
 */
function contacts_report(int $reporterId, int $reportedId, string $reason = 'spam', string $severity = 'medium'): array {
  if ($reporterId <= 0 || $reportedId <= 0 || $reporterId === $reportedId) {
    return ['ok' => false, 'error' => 'Нельзя пожаловаться'];
  }
  $reason = in_array($reason, ['spam', 'abuse', 'scam', 'other'], true) ? $reason : 'spam';
  $severity = in_array($severity, ['weak', 'medium', 'strong'], true) ? $severity : 'medium';
  contacts_ensure_schema();
  try {
    // роль цели
    $stmt = db()->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$reportedId]);
    $role = (string)($stmt->fetchColumn() ?: '');
    if (in_array($role, ['admin', 'moderator'], true)) {
      return ['ok' => false, 'error' => 'Нельзя пожаловаться на администрацию'];
    }

    db()->prepare(
      'INSERT IGNORE INTO user_reports (reporter_id, reported_id, reason, severity) VALUES (?, ?, ?, ?)'
    )->execute([$reporterId, $reportedId, $reason, $severity]);

    $autoBanned = false;
    if ($reason === 'spam') {
      $st = db()->prepare(
        "SELECT COUNT(DISTINCT reporter_id) FROM user_reports WHERE reported_id = ? AND reason = 'spam'"
      );
      $st->execute([$reportedId]);
      $cnt = (int)$st->fetchColumn();
      if ($cnt >= 10) {
        $autoBanned = contacts_ban_user(
          $reportedId,
          'medium',
          'Автобан: 10+ жалоб на спам от разных пользователей'
        );
      }
    }
    return ['ok' => true, 'auto_banned' => $autoBanned];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'Не удалось отправить жалобу'];
  }
}

/** Можно ли зрителю видеть телефон владельца профиля */
function contacts_can_see_phone(int $viewerId, int $profileUserId, bool $isOwn): bool {
  if ($isOwn) return true;
  if ($viewerId <= 0 || $profileUserId <= 0) return false;
  return contacts_are_mutual($viewerId, $profileUserId);
}
