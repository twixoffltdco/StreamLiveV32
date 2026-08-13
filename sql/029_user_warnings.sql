-- StreamLive migration 029: user_warnings + soft ban helpers
-- 30 points within 7 days → auto-ban 7 days (app logic in includes/warnings.php)

CREATE TABLE IF NOT EXISTS user_warnings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  moderator_id INT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  points INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_created (user_id, created_at),
  KEY idx_moderator (moderator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional columns on users (safe to re-run; ignore errors if exist)
-- Run via migrate_029.php which checks information_schema.

-- ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0;
-- ALTER TABLE users ADD COLUMN banned_until DATETIME NULL DEFAULT NULL;
-- ALTER TABLE users ADD COLUMN ban_reason VARCHAR(500) NULL DEFAULT NULL;
