CREATE TABLE IF NOT EXISTS github_connections (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL UNIQUE,
  github_username   VARCHAR(191) NOT NULL,
  access_token_enc  TEXT NOT NULL,
  connected_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deployed_services (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT NOT NULL,
  name            VARCHAR(150) NOT NULL,
  description     VARCHAR(500) DEFAULT NULL,
  repo_full_name  VARCHAR(255) NOT NULL,      -- owner/repo
  branch          VARCHAR(150) NOT NULL DEFAULT 'main',
  slug            VARCHAR(64) UNIQUE NOT NULL, -- открывается как /s/{slug}/
  status          ENUM('deploying','live','failed') NOT NULL DEFAULT 'deploying',
  error_message   VARCHAR(500) DEFAULT NULL,
  is_public       TINYINT(1) NOT NULL DEFAULT 0, -- показывать ли в каталоге "Сервисы"
  deployed_at     DATETIME DEFAULT NULL,
  updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
