-- Партнёрские промокоды, рефералы, soft-delete аккаунтов/брендов
-- Партнёрский код: один на пользователя, переименовать нельзя, админ/модер не отклоняют.

CREATE TABLE IF NOT EXISTS partner_promos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_partner_user (user_id),
  UNIQUE KEY uq_partner_code (code),
  KEY idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_activations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  promo_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  access_until DATETIME NOT NULL,
  UNIQUE KEY uq_user_promo (user_id, promo_id),
  KEY idx_user_until (user_id, access_until),
  KEY idx_promo (promo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_referrals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  partner_user_id INT UNSIGNED NOT NULL,
  referred_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_referred (referred_user_id),
  KEY idx_partner (partner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL;
ALTER TABLE users ADD COLUMN delete_scheduled_purge_at DATETIME NULL;
ALTER TABLE users ADD COLUMN delete_reason VARCHAR(255) NULL;
