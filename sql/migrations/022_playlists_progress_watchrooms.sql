-- Плейлисты
CREATE TABLE IF NOT EXISTS playlists (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(150) NOT NULL,
  description VARCHAR(500) DEFAULT NULL,
  slug        VARCHAR(64) NOT NULL UNIQUE,
  is_public   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  playlist_id INT UNSIGNED NOT NULL,
  video_id    INT UNSIGNED NOT NULL,
  position    INT UNSIGNED NOT NULL DEFAULT 0,
  added_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_playlist_video (playlist_id, video_id),
  KEY idx_playlist (playlist_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Продолжить просмотр (только для нативного <video> — mp4/m3u8; для YouTube/VK/итд честно
-- недоступно без глубокой интеграции с их плеерами, см. README)
CREATE TABLE IF NOT EXISTS video_watch_progress (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED NOT NULL,
  video_id         INT UNSIGNED NOT NULL,
  position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds INT UNSIGNED DEFAULT NULL,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_video (user_id, video_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Совместный просмотр (комнаты)
CREATE TABLE IF NOT EXISTS watch_rooms (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_id     INT UNSIGNED NOT NULL,
  host_user_id INT UNSIGNED NOT NULL,
  room_code    VARCHAR(12) NOT NULL UNIQUE,
  is_playing   TINYINT(1) NOT NULL DEFAULT 0,
  position_seconds DECIMAL(10,2) NOT NULL DEFAULT 0,
  state_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_room_code (room_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watch_room_messages (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id     INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED DEFAULT NULL,
  guest_name  VARCHAR(60) DEFAULT NULL,
  message     VARCHAR(500) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_room (room_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
