-- Пользовательские BBCode-теги, управляемые из /admin/bbcode_tags.php — как в XenForo,
-- где новый тег добавляется через Admin CP без единой строчки кода.

CREATE TABLE IF NOT EXISTS bbcode_custom_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tag_name VARCHAR(32) NOT NULL UNIQUE,
  replacement TEXT NOT NULL,
  example VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
