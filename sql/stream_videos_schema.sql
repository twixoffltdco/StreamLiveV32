-- StreamLive отдельный видеохостинг
CREATE TABLE IF NOT EXISTS stream_videos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  slug VARCHAR(64) NOT NULL UNIQUE,
  source_url VARCHAR(1000) NOT NULL,
  platform VARCHAR(32) NOT NULL,
  embed_url VARCHAR(1000) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  tags VARCHAR(500) DEFAULT '',
  thumbnail_url VARCHAR(1000) DEFAULT NULL,
  views_count INT UNSIGNED DEFAULT 0,
  likes_count INT UNSIGNED DEFAULT 0,
  comments_count INT UNSIGNED DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FULLTEXT KEY ft_search (title, description, tags)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stream_video_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  message VARCHAR(1000) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stream_video_likes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uniq_like (video_id, user_id)
);

CREATE TABLE IF NOT EXISTS stream_video_favorites (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uniq_fav (video_id, user_id)
);