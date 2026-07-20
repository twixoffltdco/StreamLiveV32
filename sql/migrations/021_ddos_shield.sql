-- ddos_route_log создаётся автоматически кодом при первом обращении (CREATE TABLE IF NOT EXISTS
-- прямо в includes/ddos_shield.php) — эта миграция не обязательна, но можно залить заранее.
CREATE TABLE IF NOT EXISTS ddos_route_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route VARCHAR(191) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_route_ip (route, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Настройка режима "под атакой", по умолчанию выключен
INSERT INTO settings (`key`, `value`) VALUES ('ddos_under_attack_mode', '0')
  ON DUPLICATE KEY UPDATE `key` = `key`;
