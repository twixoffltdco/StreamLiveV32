CREATE TABLE IF NOT EXISTS verification_requests (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  real_name    VARCHAR(150) NOT NULL,
  reason       TEXT NOT NULL,
  status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by  INT UNSIGNED DEFAULT NULL,
  review_note  VARCHAR(500) DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at  DATETIME DEFAULT NULL,
  KEY idx_status (status),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
