CREATE TABLE IF NOT EXISTS forum_attachments (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id      INT NOT NULL,
  url          VARCHAR(1000) NOT NULL,
  filename     VARCHAR(255) DEFAULT NULL,   -- берётся из последнего сегмента ссылки, если не задано явно
  views_count  INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_post_url (post_id, url(255)),
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
