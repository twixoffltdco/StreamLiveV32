-- Ресурсы: публикация только внешними ссылками, README, SEO/RSS и модерация скрытием.
CREATE TABLE IF NOT EXISTS resources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  slug VARCHAR(180) UNIQUE NOT NULL,
  title VARCHAR(180) NOT NULL,
  summary VARCHAR(500) DEFAULT NULL,
  readme MEDIUMTEXT,
  external_url VARCHAR(1000) NOT NULL,
  download_url VARCHAR(1000) DEFAULT NULL,
  tags VARCHAR(500) DEFAULT NULL,
  status ENUM('published','hidden') NOT NULL DEFAULT 'published',
  hidden_reason VARCHAR(500) DEFAULT NULL,
  views BIGINT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status_created (status, created_at),
  INDEX idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
