-- Три модуля разом: комментарии+просмотры постов в мессенджер-каналах, сайтовый поиск,
-- "кто сейчас на сайте" (переиспользует уже существующую page_views, см. includes/stats.php).

-- ---- 1) Комментарии + просмотры постов в мессенджер-каналах (как в Telegram) ----

CREATE TABLE IF NOT EXISTS broadcast_post_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  user_id INT NOT NULL,
  body VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (post_id) REFERENCES broadcast_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_post (post_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Просмотры считаем уникальными на пользователя (как у Telegram), а не на каждый показ —
-- поэтому отдельная таблица с PRIMARY KEY(post_id, user_id) вместо простого счётчика.
CREATE TABLE IF NOT EXISTS broadcast_post_views (
  post_id INT NOT NULL,
  user_id INT NOT NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  FOREIGN KEY (post_id) REFERENCES broadcast_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 2) Полнотекстовый поиск по сайту (search.php) ----
-- У videos FULLTEXT уже есть (019_videos.sql), здесь добавляем остальным разделам.

ALTER TABLE channels ADD FULLTEXT KEY ft_search (title, description);
ALTER TABLE broadcast_channels ADD FULLTEXT KEY ft_search (title, description);
ALTER TABLE forum_threads ADD FULLTEXT KEY ft_search (title);

-- ---- 3) "Кто сейчас на сайте" — переиспользует page_views (includes/stats.php), но там
-- не было user_agent, а без него нельзя отличить робота (Googlebot, YandexBot и т.д.)
-- от живого гостя.

ALTER TABLE page_views ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL;
ALTER TABLE page_views ADD INDEX idx_created_only (created_at);
