-- Жалобы на посты форума (если таблицы ещё нет — создаём)
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

ALTER TABLE forum_post_reports ADD COLUMN reviewed_by INT NULL;
ALTER TABLE forum_post_reports ADD COLUMN reviewed_at DATETIME NULL;
ALTER TABLE forum_post_reports ADD COLUMN review_note VARCHAR(255) NULL;
