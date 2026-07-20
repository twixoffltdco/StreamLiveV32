-- RTMP-вещание: храним данные для стороннего RTMP-приёма (ключ — в зашифрованном виде)
-- и итоговую ссылку плейбэка (HLS), которую отдаёт relay-сервис после приёма RTMP.
--
-- ВАЖНО: бесплатный shared PHP-хостинг (rf.gd и подобные) не может сам принимать RTMP —
-- для этого нужен постоянно работающий сервер (например nginx-rtmp/MediaMTX) на VPS.
-- Эти поля хранят настройки ДЛЯ ТАКОГО внешнего relay-сервера — сам StreamLive лишь
-- проигрывает итоговый HLS и проверяет, идёт ли сейчас эфир.

ALTER TABLE channels ADD COLUMN rtmp_server VARCHAR(255) DEFAULT NULL;
ALTER TABLE channels ADD COLUMN stream_key_enc VARCHAR(500) DEFAULT NULL;
ALTER TABLE channels ADD COLUMN stream_key_iv VARCHAR(64) DEFAULT NULL;
ALTER TABLE channels ADD COLUMN rtmp_playback_url VARCHAR(500) DEFAULT NULL;
