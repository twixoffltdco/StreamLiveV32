ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN password_reset_by_admin_at DATETIME DEFAULT NULL;
