-- Каналы-рассылки в мессенджере (как в Telegram) — отдельно от ТВ/радио-каналов
-- (таблица channels). Тут: публичные каналы, на которые можно подписаться, автор
-- пишет посты, подписчики читают в реальном времени (поллинг, без перезагрузки).

CREATE TABLE IF NOT EXISTS broadcast_channels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_id INT NOT NULL,
  slug VARCHAR(150) UNIQUE NOT NULL,
  title VARCHAR(150) NOT NULL,
  description VARCHAR(500) DEFAULT NULL,
  avatar_url VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS broadcast_subscribers (
  channel_id INT NOT NULL,
  user_id INT NOT NULL,
  subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (channel_id, user_id),
  FOREIGN KEY (channel_id) REFERENCES broadcast_channels(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS broadcast_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NOT NULL,
  author_id INT NOT NULL,
  body TEXT NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (channel_id) REFERENCES broadcast_channels(id) ON DELETE CASCADE,
  FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_channel (channel_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
