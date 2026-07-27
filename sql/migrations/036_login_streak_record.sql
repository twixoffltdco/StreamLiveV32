-- Рекорд серии входов — как на ProHub: текущая серия сбрасывается при пропуске дня,
-- но лучший результат за всё время сохраняется отдельно и не уменьшается.

ALTER TABLE users ADD COLUMN longest_login_streak INT NOT NULL DEFAULT 0;
