-- Запускать один раз на уже существующей установке StreamLive, если кириллица
-- отображается как "?????" (несовпадение кодировки БД/таблиц с utf8mb4).
--
-- ВАЖНО: если данные уже были испорчены (в столбцах реально хранятся байты '?',
-- а не оригинальный текст) — этот скрипт остановит дальнейшую порчу новых записей,
-- но уже испорченные строки (названия каналов, сообщения чата и т.д.) восстановить
-- нельзя, их нужно будет ввести заново через личный кабинет / админку.
--
-- Как запустить: через phpMyAdmin (вкладка SQL) либо `mysql -u USER -p DBNAME < fix_charset.sql`

ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE oauth_providers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE sources CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE channels CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE schedule CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE chat_moderators CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE chat_bans CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE stickers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE chat_messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE notifications CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
