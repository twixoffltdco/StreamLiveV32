-- Антидудос v2 — таблицы/колонки создаются автоматически при первом запросе
-- (см. includes/antibot.php: antibot_ensure_table() / antibot_ensure_global_table()),
-- этот файл — для порядка в списке миграций и на случай, если админ применяет миграции руками.

ALTER TABLE antibot_ip_log ADD COLUMN IF NOT EXISTS block_count INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS antibot_global_window (
  id TINYINT UNSIGNED PRIMARY KEY,
  window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  request_count INT NOT NULL DEFAULT 0,
  attack_until DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO antibot_global_window (id, window_start, request_count) VALUES (1, NOW(), 0);
