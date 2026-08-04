ALTER TABLE user_prefixes ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0;
-- После деплоя в админке: «Пометить старые системные (не каналы)»
