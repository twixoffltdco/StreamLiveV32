-- RSS-импорт теперь может публиковать новые записи как темы форума (а не только
-- привязываться к ТВ/радио-каналу, как раньше).
ALTER TABLE rss_sources ADD COLUMN forum_category_id INT DEFAULT NULL;
ALTER TABLE rss_sources ADD CONSTRAINT fk_rss_forum_category
  FOREIGN KEY (forum_category_id) REFERENCES forum_categories(id) ON DELETE SET NULL;
