<?php
// Общие функции для новых ограничений модерации — самомодерация, кулдаун на модерацию
// контента (24ч по умолчанию, настраивается в админке), кулдаун на назначение/снятие
// роли модератора (100ч). Настройки лежат в обычной таблице settings, как и остальные
// переключатели в проекте (get_setting()/set_setting()).

function moderation_ensure_schema(): void {
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS moderation_action_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        moderator_id INT NOT NULL,
        target_type ENUM('channel','video') NOT NULL,
        target_id INT NOT NULL,
        action VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mod_type_time (moderator_id, target_type, created_at),
        FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!table_column_exists('users', 'role_changed_at')) {
      db()->exec('ALTER TABLE users ADD COLUMN role_changed_at DATETIME DEFAULT NULL');
    }
    db()->exec(
      "CREATE TABLE IF NOT EXISTS moderation_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action_type ENUM('ban','unban','grant_moderator','revoke_moderator') NOT NULL,
        target_user_id INT NOT NULL,
        requested_by INT NOT NULL,
        reason VARCHAR(500) DEFAULT NULL,
        status ENUM('pending','approved','cancelled') NOT NULL DEFAULT 'pending',
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status),
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (\Throwable $e) { /* нет прав CREATE/ALTER — залей sql/migrations/035_moderation_limits_requests.sql руками */ }
}

// ---- 1) Запрет самомодерации ----
function no_self_moderation_enabled(): bool {
  return get_setting('no_self_moderation_enabled', '1') === '1';
}

// $contentOwnerId — владелец канала/видео (user_id/owner_id), $actorId — кто пытается модерировать.
function is_self_moderation_blocked(int $contentOwnerId, int $actorId): bool {
  return no_self_moderation_enabled() && $contentOwnerId === $actorId;
}

// ---- 2) Кулдаун модерации: 1 действие в N часов на модератора на тип контента ----
function moderation_cooldown_hours(): int {
  return max(0, (int)get_setting('moderation_cooldown_hours', '24'));
}

// Возвращает null, если можно действовать сейчас, иначе — сколько ЕЩЁ часов ждать.
// Админ не ограничен этим кулдауном — у него уже есть отдельная модель ответственности
// (последнее слово всегда за ним, см. sql/migrations/033_admin_final_say.sql), рейт-лимит
// на него смысла не имеет и мешал бы использовать право финального решения когда нужно.
function moderator_cooldown_remaining_hours(int $moderatorId, string $targetType, string $role): ?int {
  if ($role === 'admin') return null;
  $hours = moderation_cooldown_hours();
  if ($hours <= 0) return null;
  moderation_ensure_schema();
  try {
    $stmt = db()->prepare(
      'SELECT created_at FROM moderation_action_log WHERE moderator_id = ? AND target_type = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$moderatorId, $targetType]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $elapsedHours = (time() - strtotime($row['created_at'])) / 3600;
    if ($elapsedHours >= $hours) return null;
    return (int)ceil($hours - $elapsedHours);
  } catch (\Throwable $e) {
    return null; // таблицы ещё нет — не блокируем модерацию из-за нашей же временной проблемы
  }
}

function moderation_log_action(int $moderatorId, string $targetType, int $targetId, string $action): void {
  moderation_ensure_schema();
  try {
    db()->prepare('INSERT INTO moderation_action_log (moderator_id, target_type, target_id, action) VALUES (?, ?, ?, ?)')
      ->execute([$moderatorId, $targetType, $targetId, $action]);
  } catch (\Throwable $e) { /* не критично для самого действия модерации */ }
}

// ---- 3) Кулдаун на назначение/снятие роли модератора: 100 часов ----
function role_change_cooldown_hours(): int {
  return max(0, (int)get_setting('role_change_cooldown_hours', '100'));
}

// $targetUser — массив пользователя, у которого меняют роль (нужен role_changed_at).
function role_change_cooldown_remaining_hours(array $targetUser): ?int {
  $hours = role_change_cooldown_hours();
  if ($hours <= 0 || empty($targetUser['role_changed_at'])) return null;
  $elapsedHours = (time() - strtotime($targetUser['role_changed_at'])) / 3600;
  if ($elapsedHours >= $hours) return null;
  return (int)ceil($hours - $elapsedHours);
}
