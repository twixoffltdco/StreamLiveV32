-- Реакции на посты в мессенджер-каналах — одна реакция на пользователя на пост
-- (повторный клик на ту же меняет/убирает, клик на другую — заменяет, как в Telegram).

CREATE TABLE IF NOT EXISTS broadcast_post_reactions (
  post_id INT NOT NULL,
  user_id INT NOT NULL,
  emoji VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  FOREIGN KEY (post_id) REFERENCES broadcast_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
