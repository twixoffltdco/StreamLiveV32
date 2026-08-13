<?php
/**
 * Battle Pass — сезоны, уровни, задания, награды (промо 3 дня / XP / префикс).
 */
function bp_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $pdo = db();
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS bp_seasons (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS bp_levels (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        season_id INT UNSIGNED NOT NULL,
        level_num INT UNSIGNED NOT NULL DEFAULT 1,
        xp_required INT UNSIGNED NOT NULL DEFAULT 100,
        reward_type VARCHAR(32) NOT NULL DEFAULT 'xp',
        reward_value VARCHAR(255) NULL,
        reward_label VARCHAR(120) NULL,
        UNIQUE KEY uq_season_lvl (season_id, level_num),
        KEY idx_season (season_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS bp_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        season_id INT UNSIGNED NOT NULL,
        code VARCHAR(64) NOT NULL,
        title VARCHAR(160) NOT NULL,
        description VARCHAR(255) NULL,
        xp_reward INT UNSIGNED NOT NULL DEFAULT 50,
        target_count INT UNSIGNED NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_season (season_id),
        KEY idx_code (code)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS bp_user_progress (
        user_id INT UNSIGNED NOT NULL,
        season_id INT UNSIGNED NOT NULL,
        xp INT UNSIGNED NOT NULL DEFAULT 0,
        level_num INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, season_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS bp_user_tasks (
        user_id INT UNSIGNED NOT NULL,
        task_id INT UNSIGNED NOT NULL,
        progress INT UNSIGNED NOT NULL DEFAULT 0,
        completed_at DATETIME NULL,
        PRIMARY KEY (user_id, task_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS bp_user_rewards (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        season_id INT UNSIGNED NOT NULL,
        level_id INT UNSIGNED NOT NULL,
        claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_claim (user_id, level_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function bp_active_season(): ?array {
  bp_ensure_schema();
  try {
    $r = db()->query(
      "SELECT * FROM bp_seasons WHERE is_active=1
       AND (starts_at IS NULL OR starts_at <= NOW())
       AND (ends_at IS NULL OR ends_at >= NOW())
       ORDER BY id DESC LIMIT 1"
    )->fetch();
    return $r ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function bp_task_codes(): array {
  return [
    'login_daily' => 'Ежедневный вход',
    'forum_post' => 'Сообщение на форуме',
    'forum_thread' => 'Новая тема на форуме',
    'watch_video' => 'Просмотр видео',
    'favorite_channel' => 'Канал в избранное',
    'flex_build' => 'Постройка в Flex Blocks',
    'flex_publish' => 'Публикация карты Flex Blocks',
    'flex_play' => 'Игра в Flex Blocks',
  ];
}

/** Прогресс задания + XP в сезон */
function bp_progress_task(int $userId, string $code, int $inc = 1): void {
  if ($userId <= 0 || $code === '') return;
  bp_ensure_schema();
  $season = bp_active_season();
  if (!$season) return;
  $sid = (int)$season['id'];
  try {
    $st = db()->prepare(
      'SELECT * FROM bp_tasks WHERE season_id=? AND code=? AND is_active=1 LIMIT 1'
    );
    $st->execute([$sid, $code]);
    $task = $st->fetch();
    if (!$task) return;
    $tid = (int)$task['id'];
    $target = max(1, (int)$task['target_count']);
    $xpRew = max(0, (int)$task['xp_reward']);

    db()->prepare(
      'INSERT INTO bp_user_tasks (user_id, task_id, progress) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE progress = LEAST(progress + VALUES(progress), 999999)'
    )->execute([$userId, $tid, $inc]);

    $st = db()->prepare('SELECT progress, completed_at FROM bp_user_tasks WHERE user_id=? AND task_id=?');
    $st->execute([$userId, $tid]);
    $row = $st->fetch() ?: ['progress' => 0, 'completed_at' => null];
    if (!empty($row['completed_at'])) return;
    if ((int)$row['progress'] < $target) return;

    db()->prepare('UPDATE bp_user_tasks SET completed_at=NOW() WHERE user_id=? AND task_id=?')->execute([$userId, $tid]);
    if ($xpRew > 0) bp_add_xp($userId, $sid, $xpRew);
  } catch (Throwable $e) {}
}

function bp_add_xp(int $userId, int $seasonId, int $xp): void {
  if ($xp <= 0) return;
  bp_ensure_schema();
  try {
    db()->prepare(
      'INSERT INTO bp_user_progress (user_id, season_id, xp, level_num) VALUES (?,?,?,0)
       ON DUPLICATE KEY UPDATE xp = xp + VALUES(xp)'
    )->execute([$userId, $seasonId, $xp]);
    bp_recalc_level($userId, $seasonId);
  } catch (Throwable $e) {}
}

function bp_recalc_level(int $userId, int $seasonId): void {
  try {
    $st = db()->prepare('SELECT xp FROM bp_user_progress WHERE user_id=? AND season_id=?');
    $st->execute([$userId, $seasonId]);
    $xp = (int)($st->fetchColumn() ?: 0);
    $levels = db()->prepare('SELECT level_num, xp_required FROM bp_levels WHERE season_id=? ORDER BY level_num ASC');
    $levels->execute([$seasonId]);
    $lvl = 0;
    $cum = 0;
    foreach ($levels->fetchAll() ?: [] as $L) {
      $cum += (int)$L['xp_required'];
      if ($xp >= $cum) $lvl = (int)$L['level_num'];
      else break;
    }
    db()->prepare('UPDATE bp_user_progress SET level_num=? WHERE user_id=? AND season_id=?')
      ->execute([$lvl, $userId, $seasonId]);
  } catch (Throwable $e) {}
}

function bp_user_state(int $userId): array {
  bp_ensure_schema();
  $season = bp_active_season();
  if (!$season) return ['season' => null];
  $sid = (int)$season['id'];
  $prog = ['xp' => 0, 'level_num' => 0];
  try {
    $st = db()->prepare('SELECT xp, level_num FROM bp_user_progress WHERE user_id=? AND season_id=?');
    $st->execute([$userId, $sid]);
    $prog = $st->fetch() ?: $prog;
  } catch (Throwable $e) {}
  $levels = [];
  $tasks = [];
  try {
    $st = db()->prepare('SELECT * FROM bp_levels WHERE season_id=? ORDER BY level_num ASC');
    $st->execute([$sid]);
    $levels = $st->fetchAll() ?: [];
  } catch (Throwable $e) {}
  try {
    $st = db()->prepare(
      'SELECT t.*, ut.progress AS user_progress, ut.completed_at
       FROM bp_tasks t
       LEFT JOIN bp_user_tasks ut ON ut.task_id=t.id AND ut.user_id=?
       WHERE t.season_id=? AND t.is_active=1 ORDER BY t.id ASC'
    );
    $st->execute([$userId, $sid]);
    $tasks = $st->fetchAll() ?: [];
  } catch (Throwable $e) {}
  return ['season' => $season, 'progress' => $prog, 'levels' => $levels, 'tasks' => $tasks];
}

/** Забрать награду уровня (промокод 3 дня / xp платформы) */
function bp_claim_level(int $userId, int $levelId): array {
  bp_ensure_schema();
  if (!function_exists('paid_ensure_schema')) {
    $f = __DIR__ . '/paid_access.php';
    if (is_file($f)) require_once $f;
  }
  try {
    $st = db()->prepare('SELECT * FROM bp_levels WHERE id=?');
    $st->execute([$levelId]);
    $lvl = $st->fetch();
    if (!$lvl) return ['ok' => false, 'error' => 'Уровень не найден'];
    $sid = (int)$lvl['season_id'];
    $st = db()->prepare('SELECT level_num, xp FROM bp_user_progress WHERE user_id=? AND season_id=?');
    $st->execute([$userId, $sid]);
    $prog = $st->fetch();
    if (!$prog || (int)$prog['level_num'] < (int)$lvl['level_num']) {
      return ['ok' => false, 'error' => 'Уровень ещё не открыт'];
    }
    $st = db()->prepare('SELECT id FROM bp_user_rewards WHERE user_id=? AND level_id=?');
    $st->execute([$userId, $levelId]);
    if ($st->fetch()) return ['ok' => false, 'error' => 'Уже получено'];

    $type = (string)$lvl['reward_type'];
    $val = trim((string)($lvl['reward_value'] ?? ''));
    $msg = 'Награда получена';

    if ($type === 'promo' && $val !== '' && function_exists('paid_activate_code')) {
      if (!function_exists('paid_ensure_schema')) { /* */ }
      paid_ensure_schema();
      $stP = db()->prepare('SELECT channel_id FROM promo_codes WHERE code = ? LIMIT 1');
      $stP->execute([strtoupper(trim($val))]);
      $chId = (int)($stP->fetchColumn() ?: 0);
      $r = paid_activate_code($userId, $val, $chId, true);
      $msg = $r['message'] ?? ($r['ok'] ? 'Промокод на 3 дня активирован' : ($r['error'] ?? 'Ошибка промо'));
      if (empty($r['ok'])) return ['ok' => false, 'error' => $msg];
    } elseif ($type === 'xp' && function_exists('register_daily_activity')) {
      // просто +xp в users если есть колонка
      try {
        $add = max(0, (int)$val);
        if ($add > 0) db()->prepare('UPDATE users SET xp = xp + ? WHERE id=?')->execute([$add, $userId]);
        $msg = "+{$add} XP платформы";
      } catch (Throwable $e) {}
    } elseif ($type === 'prefix' && $val !== '') {
      try {
        if (function_exists('user_set_prefixes')) {
          user_set_prefixes($userId, [(int)$val]);
          $msg = 'Префикс выдан';
        }
      } catch (Throwable $e) {}
    }

    db()->prepare(
      'INSERT INTO bp_user_rewards (user_id, season_id, level_id) VALUES (?,?,?)'
    )->execute([$userId, $sid, $levelId]);
    return ['ok' => true, 'message' => $msg];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}
