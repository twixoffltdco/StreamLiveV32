<?php
// Геймификация в духе рейтинговой системы форума ProHub: заходишь каждый день —
// растёт total_active_days и xp. При достижении 4000 дней активности счётчик дней
// уходит в 0, а cycle_number увеличивается (как "престиж"/новый круг) — накопленный
// XP и ранг при этом НЕ обнуляются, обнуляется только счётчик дней текущего круга.
// Если нужна другая логика (например, полный сброс XP при новом круге) — это
// единственное место, где это нужно поменять (см. register_daily_activity).

// Лесенка рангов. Можно свободно переименовать/перенастроить пороги под систему ProHub.
const XP_RANKS = [
  ['min' => 0,     'title' => 'Новичок'],
  ['min' => 100,   'title' => 'Участник'],
  ['min' => 300,   'title' => 'Активист'],
  ['min' => 700,   'title' => 'Знаток'],
  ['min' => 1500,  'title' => 'Эксперт'],
  ['min' => 3000,  'title' => 'Профи'],
  ['min' => 6000,  'title' => 'Мастер'],
  ['min' => 12000, 'title' => 'Гуру'],
  ['min' => 25000, 'title' => 'Легенда'],
  ['min' => 50000, 'title' => 'Легенда платформы'],
];

const CYCLE_LENGTH_DAYS = 4000;

function get_rank_for_xp(int $xp): array {
  $current = XP_RANKS[0];
  $next = null;
  foreach (XP_RANKS as $i => $rank) {
    if ($xp >= $rank['min']) {
      $current = $rank;
      $next = XP_RANKS[$i + 1] ?? null;
    }
  }
  $progress = 100;
  if ($next) {
    $span = $next['min'] - $current['min'];
    $progress = $span > 0 ? (int)round((($xp - $current['min']) / $span) * 100) : 100;
  }
  return ['title' => $current['title'], 'next_title' => $next['title'] ?? null, 'next_min' => $next['min'] ?? null, 'progress_percent' => $progress];
}

// Вызывать при каждом заходе (см. хук в includes/header.php — срабатывает сам,
// не завязано на ручной патч login_user(), поэтому работает даже если сессия уже
// была активна). Начисляет XP не чаще раза в календарные сутки.
// Возвращает null, если сегодня уже засчитано, либо массив с данными для баннера-приветствия.
function register_daily_activity(int $userId): ?array {
  if (!table_column_exists('users', 'longest_login_streak')) {
    try { db()->exec('ALTER TABLE users ADD COLUMN longest_login_streak INT NOT NULL DEFAULT 0'); } catch (\Throwable $e) { }
  }

  $stmt = db()->prepare('SELECT xp, total_active_days, cycle_number, login_streak_days, longest_login_streak, last_active_date FROM users WHERE id = ?');
  $stmt->execute([$userId]);
  $u = $stmt->fetch();
  if (!$u) return null;

  $today = date('Y-m-d');
  if ($u['last_active_date'] === $today) return null; // уже засчитано сегодня

  $yesterday = date('Y-m-d', strtotime('-1 day'));
  $missedDay = ($u['last_active_date'] !== $yesterday && $u['last_active_date'] !== null && $u['last_active_date'] !== '');
  // Пропуск дня → начинаем с начала (XP и дни сбрасываются), роль (admin/mod) НЕ трогаем
  if ($missedDay) {
    $streak = 1;
    $activeDays = 1;
    $cycle = (int)$u['cycle_number'];
    $xpGain = 20;
    $longestStreak = max((int)($u['longest_login_streak'] ?? 0), 1);
    db()->prepare('UPDATE users SET xp = ?, total_active_days = ?, cycle_number = ?, login_streak_days = ?, longest_login_streak = ?, last_active_date = ? WHERE id = ?')
      ->execute([$xpGain, $activeDays, $cycle, $streak, $longestStreak, $today, $userId]);
    return ['xp_gained' => $xpGain, 'streak' => $streak, 'longest_streak' => $longestStreak, 'reset' => true, 'new_place' => get_user_rating_place($userId)];
  }

  $streak = ($u['last_active_date'] === $yesterday) ? (int)$u['login_streak_days'] + 1 : 1;
  $longestStreak = max((int)($u['longest_login_streak'] ?? 0), $streak);
  $xpGain = 20 + min($streak, 30) * 2;
  $activeDays = (int)$u['total_active_days'] + 1;
  $cycle = (int)$u['cycle_number'];
  if ($activeDays >= CYCLE_LENGTH_DAYS) {
    $activeDays = 0;
    $cycle += 1;
  }

  db()->prepare('UPDATE users SET xp = xp + ?, total_active_days = ?, cycle_number = ?, login_streak_days = ?, longest_login_streak = ?, last_active_date = ? WHERE id = ?')
    ->execute([$xpGain, $activeDays, $cycle, $streak, $longestStreak, $today, $userId]);

  return ['xp_gained' => $xpGain, 'streak' => $streak, 'longest_streak' => $longestStreak, 'new_place' => get_user_rating_place($userId)];
}

function get_user_gamification(int $userId): ?array {
  $stmt = db()->prepare('SELECT xp, total_active_days, cycle_number, login_streak_days FROM users WHERE id = ?');
  $stmt->execute([$userId]);
  $u = $stmt->fetch();
  if (!$u) return null;
  $rank = get_rank_for_xp((int)$u['xp']);
  return array_merge($u, $rank, ['cycle_length' => CYCLE_LENGTH_DAYS]);
}

// Место пользователя в общем рейтинге (по XP), 1 = первое место
function get_user_rating_place(int $userId): int {
  $stmt = db()->prepare('SELECT COUNT(*) + 1 FROM users u2 JOIN users u1 ON u1.id = ? WHERE u2.xp > u1.xp');
  $stmt->execute([$userId]);
  return (int)$stmt->fetchColumn();
}
