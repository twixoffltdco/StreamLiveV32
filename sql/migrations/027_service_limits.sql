ALTER TABLE deployed_services ADD COLUMN suspended TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE deployed_services ADD COLUMN suspended_reason VARCHAR(255) DEFAULT NULL;
-- unlimited_deploys — снимается с users.is_verified (та же галочка "доверенный"), отдельного поля не заводим
