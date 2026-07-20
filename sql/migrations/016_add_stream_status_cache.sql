-- Кэш последнего известного статуса "жив ли поток" — чтобы владелец видел
-- офлайн/онлайн в панели управления каналом, не заходя на страницу плеера.
ALTER TABLE channels ADD COLUMN last_stream_live TINYINT(1) DEFAULT NULL;
ALTER TABLE channels ADD COLUMN last_stream_checked_at DATETIME DEFAULT NULL;
