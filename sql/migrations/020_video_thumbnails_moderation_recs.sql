-- Обложка теперь может быть base64-кадром, снятым браузером из mp4/m3u8 (для таких
-- потоков сервер не может получить превью иначе — нет ffmpeg на бесплатном хостинге)
ALTER TABLE videos MODIFY COLUMN thumbnail_url MEDIUMTEXT DEFAULT NULL;

-- Модерация видео перед публикацией
ALTER TABLE videos MODIFY COLUMN status ENUM('pending','published','rejected','hidden') NOT NULL DEFAULT 'pending';
ALTER TABLE videos ADD COLUMN reject_reason VARCHAR(500) DEFAULT NULL;

-- История просмотров для рекомендаций (у залогиненных — по user_id, у анонимов — по cookie-идентификатору)
CREATE TABLE IF NOT EXISTS video_views_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_id    INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED DEFAULT NULL,
  visitor_id  VARCHAR(64) DEFAULT NULL, -- анонимный id из cookie, если не залогинен
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_video (video_id),
  KEY idx_user (user_id),
  KEY idx_visitor (visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
