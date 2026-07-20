-- Статистика посещений — для админки и для заявок в рекламные сети (РСЯ и др.),
-- которым нужны реальные цифры трафика. Каждая страница логирует один просмотр.
CREATE TABLE IF NOT EXISTS page_views (
  id INT AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at),
  INDEX idx_path (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
