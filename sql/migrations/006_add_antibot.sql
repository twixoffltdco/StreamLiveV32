-- «Ты не робот?» — защита от дудоса/ботов. Таблица создаётся автоматически при первом
-- запросе (см. includes/antibot.php: antibot_ensure_table()), этот файл — для порядка
-- в списке миграций и на случай, если админ применяет миграции руками.

CREATE TABLE IF NOT EXISTS antibot_ip_log (
  ip VARCHAR(45) PRIMARY KEY,
  request_count INT NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  blocked_until DATETIME DEFAULT NULL,
  captcha_code VARCHAR(10) DEFAULT NULL,
  captcha_expires DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
