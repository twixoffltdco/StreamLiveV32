-- API-ключи: у каждого пользователя может быть один активный ключ, сгенерированный из
-- личного кабинета. Ключ автоматически считается "протухшим" через 30 дней (api_auth.php
-- сам перегенерирует при следующем обращении — крон не нужен). Лог использования нужен для
-- двух вещей: 1) определить "утёк" ли ключ (слишком много разных IP используют один ключ
-- одновременно — явно не один легитимный клиент), 2) посчитать нагрузку без ключа для
-- троттлинга по нагруженности.

CREATE TABLE IF NOT EXISTS api_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  api_key VARCHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rotated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_flagged_leaked TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_key_usage_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  api_key_id INT NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_key_time (api_key_id, created_at),
  FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Отдельная блокировка на 100ч для запросов БЕЗ ключа во время атаки — та же таблица,
-- что уже использует антибот (antibot_ip_log), но с префиксом 'apikeyless:' в ip, чтобы
-- не путать со счётчиком обычных гостевых просмотров страниц с этого же IP.
