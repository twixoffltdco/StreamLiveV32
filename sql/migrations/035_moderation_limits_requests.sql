-- 1) Запрет самомодерации (вкл/выкл в админке через settings: no_self_moderation_enabled)
--    и лимит модерации 24ч на модератора на тип контента (moderation_cooldown_hours в settings)
-- 2) Кулдаун назначения/снятия роли модератора — 100 часов
-- 3) Двухшаговое подтверждение бан/разбан и выдача/снятие роли модератора —
--    запрос создаёт один человек, применяет ДРУГОЙ модератор/админ

CREATE TABLE IF NOT EXISTS moderation_action_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  moderator_id INT NOT NULL,
  target_type ENUM('channel','video') NOT NULL,
  target_id INT NOT NULL,
  action VARCHAR(20) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mod_type_time (moderator_id, target_type, created_at),
  FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN role_changed_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS moderation_requests (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
