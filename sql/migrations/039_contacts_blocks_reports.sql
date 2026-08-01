-- Контакты (как в Telegram), ЧС, жалобы на спам, срок бана
CREATE TABLE IF NOT EXISTS user_contacts (
  user_id INT NOT NULL,
  contact_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, contact_user_id),
  KEY idx_contact (contact_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_blocks (
  blocker_id INT NOT NULL,
  blocked_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_id, blocked_id),
  KEY idx_blocked (blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT NOT NULL,
  reported_id INT NOT NULL,
  reason ENUM('spam','abuse','scam','other') NOT NULL DEFAULT 'spam',
  severity ENUM('weak','medium','strong') NOT NULL DEFAULT 'medium',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reporter_reported_reason (reporter_id, reported_id, reason),
  KEY idx_reported (reported_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Срок бана (NULL = бессрочно при is_banned=1 без даты, либо не забанен)
-- ALTER безопасны: на хостинге без прав self-healing в PHP проглотит ошибку
ALTER TABLE users ADD COLUMN banned_until DATETIME DEFAULT NULL;
ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) DEFAULT NULL;
