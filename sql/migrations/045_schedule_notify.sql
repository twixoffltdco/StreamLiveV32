CREATE TABLE IF NOT EXISTS schedule_notify_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL,
  slot_date DATE NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slot (schedule_id, kind, slot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications ADD COLUMN link VARCHAR(500) DEFAULT NULL;
