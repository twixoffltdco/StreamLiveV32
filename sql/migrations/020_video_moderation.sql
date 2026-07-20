-- Модерация видео для StreamLive v12
-- Выполнить один раз через install-wizard / phpMyAdmin на своём хостинге, после 019_videos.sql.
-- Добавляет в таблицу videos поля для отклонения/восстановления модератором
-- (по тому же принципу, что уже работает для channels в moderator/flagged.php).

ALTER TABLE videos
  ADD COLUMN reject_reason VARCHAR(500) DEFAULT NULL AFTER status,
  ADD COLUMN moderated_by  INT UNSIGNED DEFAULT NULL AFTER reject_reason,
  ADD COLUMN moderated_at  DATETIME DEFAULT NULL AFTER moderated_by;
