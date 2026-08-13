-- НАСТОЯЩАЯ причина "с галочкой всё равно не вставляется file://": колонка sources.type — это
-- ENUM('mp4','m3u8','youtube','vk','rutube','iframe'), в котором никогда не было значения
-- 'direct'. channel_manage.php проверял тип 'direct' в PHP-логике (isDirectStreamType), но
-- в форме такой опции не было вообще, а если бы и была — INSERT с type='direct' падал бы с
-- ошибкой "Data truncated for column 'type'" (MySQL обрезает недопустимое ENUM-значение до
-- пустой строки и потом отклоняет вставку). Добавляем 'direct' как валидное значение ENUM.

ALTER TABLE sources MODIFY COLUMN type ENUM('mp4','m3u8','youtube','vk','rutube','iframe','direct') NOT NULL;
