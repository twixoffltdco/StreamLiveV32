-- История смены ников: при каждой смене старый/новый ник + дата сохраняются сюда и
-- показываются в /account_settings.php, даже если ник потом поменяют ещё раз.

CREATE TABLE IF NOT EXISTS username_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  old_username VARCHAR(64) NOT NULL,
  new_username VARCHAR(64) NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN username_changed_at DATETIME DEFAULT NULL;
