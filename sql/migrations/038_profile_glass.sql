-- Профиль в стиле «жидкое стекло»: обложка и статусная строка.
-- Безопасно: только ADD COLUMN IF NOT EXISTS через отдельный try на хостинге
-- (ниже дублируется self-healing в profile.php).

ALTER TABLE users ADD COLUMN profile_cover_url VARCHAR(500) DEFAULT NULL;
ALTER TABLE users ADD COLUMN profile_status_text VARCHAR(120) DEFAULT NULL;
