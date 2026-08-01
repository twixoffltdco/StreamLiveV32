-- До 3 префиксов на аккаунт + колонка username_css (если ещё нет)
-- Self-healing также есть в includes/user_display.php::user_display_ensure_schema()

CREATE TABLE IF NOT EXISTS user_prefix_map (
  user_id INT UNSIGNED NOT NULL,
  prefix_id INT UNSIGNED NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, prefix_id),
  KEY idx_user_sort (user_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Перенос старых одиночных prefix_id
INSERT IGNORE INTO user_prefix_map (user_id, prefix_id, sort_order)
SELECT id, prefix_id, 0 FROM users
WHERE prefix_id IS NOT NULL AND prefix_id > 0;

-- username_css (если колонки нет — раскомментируй / выполни вручную при отсутствии прав CREATE)
-- ALTER TABLE users ADD COLUMN username_css TEXT NULL;
