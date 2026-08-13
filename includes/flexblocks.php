<?php
function fb_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS fb_maps (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(120) NOT NULL,
        description VARCHAR(255) NULL,
        map_json MEDIUMTEXT NOT NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        plays INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_pub (is_published, updated_at),
        KEY idx_user (user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}
