-- Приватность ТВ/радио-каналов: автор сам решает, показывать ли канал у себя в
-- публичном профиле (по умолчанию — публичный).
ALTER TABLE channels ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1;
