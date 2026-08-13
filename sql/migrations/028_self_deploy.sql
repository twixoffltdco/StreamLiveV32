-- Лог автодеплоя (github_self_update_webhook.php) — что задеплоилось, когда, с каким
-- результатом. Настройки самого автодеплоя (секрет, репозиторий, ветка) хранятся в
-- обычной таблице settings (get_setting/set_setting), отдельные колонки под них не нужны.

CREATE TABLE IF NOT EXISTS self_deploy_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  commit_sha VARCHAR(64) DEFAULT NULL,
  commit_message VARCHAR(500) DEFAULT NULL,
  status ENUM('success','failed') NOT NULL,
  error_message VARCHAR(1000) DEFAULT NULL,
  files_updated INT DEFAULT NULL,
  duration_ms INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
