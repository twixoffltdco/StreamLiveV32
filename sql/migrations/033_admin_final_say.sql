-- "Последнее слово за админом": если админ отклонил канал/видео, модератор больше не может
-- просто взять и одобрить его обратно — только сам админ может отменить своё решение.
-- Форум это НЕ затрагивает — там модерация остаётся как была, без такой иерархии.

ALTER TABLE channels ADD COLUMN locked_by_admin TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE videos ADD COLUMN locked_by_admin TINYINT(1) NOT NULL DEFAULT 0;
