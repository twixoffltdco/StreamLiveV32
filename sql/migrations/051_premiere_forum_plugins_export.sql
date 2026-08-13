-- Премьеры видео
ALTER TABLE videos ADD COLUMN premiere_at DATETIME NULL DEFAULT NULL;
ALTER TABLE videos ADD COLUMN is_premiere TINYINT(1) NOT NULL DEFAULT 0;

-- Теги форума
CREATE TABLE IF NOT EXISTS forum_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  uses_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug),
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forum_thread_tags (
  thread_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (thread_id, tag_id),
  KEY idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Опросы в темах
CREATE TABLE IF NOT EXISTS forum_polls (
  id INT AUTO_INCREMENT PRIMARY KEY,
  thread_id INT NOT NULL,
  question VARCHAR(300) NOT NULL,
  options_json TEXT NOT NULL,
  is_multi TINYINT(1) NOT NULL DEFAULT 0,
  closes_at DATETIME NULL DEFAULT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_thread (thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forum_poll_votes (
  poll_id INT NOT NULL,
  user_id INT NOT NULL,
  option_idx TINYINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (poll_id, user_id, option_idx),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
