-- Forum engine (лайки, подписки, прочитанное, жалобы, правки) — production migration
CREATE TABLE IF NOT EXISTS forum_post_likes (
  post_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_thread_watch (
  thread_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (thread_id, user_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_thread_reads (
  thread_id INT NOT NULL,
  user_id INT NOT NULL,
  last_post_id INT NOT NULL DEFAULT 0,
  last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (thread_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_post_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  reporter_id INT NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  KEY idx_status (status),
  KEY idx_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_thread_prefixes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NULL,
  title VARCHAR(64) NOT NULL,
  css VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE forum_posts ADD COLUMN updated_at DATETIME NULL;
ALTER TABLE forum_posts ADD COLUMN edited_by INT NULL;
ALTER TABLE forum_posts ADD COLUMN like_count INT NOT NULL DEFAULT 0;
ALTER TABLE forum_threads ADD COLUMN reply_count INT NOT NULL DEFAULT 0;
ALTER TABLE forum_threads ADD COLUMN prefix_id INT NULL;
ALTER TABLE forum_threads ADD COLUMN last_post_user_id INT NULL;
