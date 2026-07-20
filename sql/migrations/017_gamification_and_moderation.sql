-- Геймификация: опыт, ранг, счётчик активных дней с циклом до 4000
ALTER TABLE users
  ADD COLUMN xp INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN total_active_days INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN cycle_number INT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN login_streak_days INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN last_active_date DATE DEFAULT NULL;

-- Панель модераторов: канал/радио можно скрыть отдельно от "отклонён на этапе модерации"
ALTER TABLE channels
  MODIFY COLUMN status ENUM('pending','approved','rejected','hidden') DEFAULT 'pending',
  ADD COLUMN hidden_reason VARCHAR(500) DEFAULT NULL,
  ADD COLUMN hidden_by INT DEFAULT NULL,
  ADD COLUMN hidden_at DATETIME DEFAULT NULL;
