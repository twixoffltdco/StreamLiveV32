-- Гео-блокировка: кэш "IP -> страна", чтобы не дёргать внешний сервис на каждый
-- заход одного и того же посетителя. Включение/выключение — через settings
-- (ключ 'geo_restrict_enabled'), управляется из админки.
CREATE TABLE IF NOT EXISTS geo_ip_cache (
  ip VARCHAR(45) PRIMARY KEY,
  country_code VARCHAR(5) DEFAULT NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Галочки верификации (как в Telegram) — выдаёт админ вручную.
ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0;
