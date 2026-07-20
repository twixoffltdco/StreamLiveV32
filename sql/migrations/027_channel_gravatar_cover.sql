-- Gravatar (как в профиле пользователя) и обложка/баннер для ТВ/радио каналов.

ALTER TABLE channels ADD COLUMN gravatar_email VARCHAR(191) DEFAULT NULL;
ALTER TABLE channels ADD COLUMN cover_url VARCHAR(500) DEFAULT NULL;
