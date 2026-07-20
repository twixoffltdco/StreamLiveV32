-- Стикер-паки как в Telegram: пользователь создаёт пак, добавляет стикеры (ссылки на
-- картинки), публикует — другие пользователи могут "добавить" пак себе. Стикеры из
-- любого пака отображаются везде, где есть текст с их кодом (:code:) — в комментариях
-- ТВ/радио-каналов и в живом чате, как и раньше работали стикеры канала.

CREATE TABLE IF NOT EXISTS sticker_packs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_id INT NOT NULL,
  title VARCHAR(100) NOT NULL,
  slug VARCHAR(120) UNIQUE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sticker_pack_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pack_id INT NOT NULL,
  code VARCHAR(60) NOT NULL,
  image_url VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_code (code),
  FOREIGN KEY (pack_id) REFERENCES sticker_packs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sticker_pack_subscriptions (
  pack_id INT NOT NULL,
  user_id INT NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pack_id, user_id),
  FOREIGN KEY (pack_id) REFERENCES sticker_packs(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
