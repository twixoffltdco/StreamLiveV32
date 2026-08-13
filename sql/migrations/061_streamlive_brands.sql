-- StreamLive бренды (brand accounts / организации)
-- Бренд = запись users с is_brand=1. Действия (посты, комментарии, каналы, Flex) идут от user_id бренда.
-- Команда: brand_members (owner / admin / editor). Лимит 20 брендов на владельца — в коде.

ALTER TABLE users ADD COLUMN is_brand TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN brand_owner_id INT UNSIGNED NULL;
ALTER TABLE users ADD COLUMN brand_verified TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS brand_members (
  brand_user_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('owner','admin','editor') NOT NULL DEFAULT 'editor',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (brand_user_id, user_id),
  KEY idx_member (user_id),
  KEY idx_brand (brand_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
