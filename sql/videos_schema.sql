-- Модуль "Видеохостинг" для StreamLive v12
-- Выполнить один раз через install-wizard / phpMyAdmin на своём хостинге.
-- Использует существующие таблицы `channels` и `users` (FK на channels.id / users.id).

CREATE TABLE IF NOT EXISTS videos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel_id      INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  slug            VARCHAR(64) NOT NULL UNIQUE,
  source_url      VARCHAR(1000) NOT NULL,        -- исходная ссылка, которую вставил пользователь
  platform        VARCHAR(32) NOT NULL,           -- youtube / vk / rutube / tiktok / vimeo / dailymotion / twitch / kick / okru / mp4 / m3u8 / iframe
  embed_url       VARCHAR(1000) NOT NULL,          -- нормализованная embed-ссылка для плеера
  title           VARCHAR(255) NOT NULL DEFAULT '',
  description     TEXT,
  tags            VARCHAR(500) DEFAULT '',         -- через запятую
  thumbnail_url   VARCHAR(1000) DEFAULT NULL,
  duration_sec    INT UNSIGNED DEFAULT NULL,
  status          ENUM('processing','published','rejected','hidden') NOT NULL DEFAULT 'processing',
  views_count     INT UNSIGNED NOT NULL DEFAULT 0,
  likes_count     INT UNSIGNED NOT NULL DEFAULT 0,
  comments_count  INT UNSIGNED NOT NULL DEFAULT 0,
  meta_source     ENUM('oembed','opengraph','manual','none') NOT NULL DEFAULT 'none',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_channel (channel_id),
  KEY idx_status (status),
  KEY idx_platform (platform),
  FULLTEXT KEY ft_search (title, description, tags)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_id    INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  message     VARCHAR(1000) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_video (video_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_likes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_id    INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_like (video_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_favorites (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_id    INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_fav (video_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
