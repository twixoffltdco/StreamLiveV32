<?php
/**
 * Префиксы (XenForo-style), CSS ника, сессия на платформе, мини-профиль.
 * До 3 префиксов на аккаунт через user_prefix_map.
 */

function user_display_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_prefixes (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(80) NOT NULL,
      css TEXT NULL,
      text_color VARCHAR(20) DEFAULT '#ffffff',
      bg_color VARCHAR(20) DEFAULT '#6366f1',
      sort_order INT NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) {}

  // До 3 префиксов на пользователя (связь many-to-many)
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_prefix_map (
      user_id INT UNSIGNED NOT NULL,
      prefix_id INT UNSIGNED NOT NULL,
      sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY (user_id, prefix_id),
      KEY idx_user_sort (user_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) {}

  $cols = [
    'prefix_id' => 'INT UNSIGNED NULL',
    'username_css' => 'TEXT NULL',
    'session_started_at' => 'DATETIME NULL',
    'last_seen_at' => 'DATETIME NULL',
    'session_seconds' => 'INT UNSIGNED NOT NULL DEFAULT 0',
  ];
  foreach ($cols as $col => $def) {
    try {
      if (function_exists('table_column_exists')) {
        if (!table_column_exists('users', $col)) {
          db()->exec("ALTER TABLE users ADD COLUMN `{$col}` {$def}");
        }
      } else {
        db()->exec("ALTER TABLE users ADD COLUMN `{$col}` {$def}");
      }
    } catch (Throwable $e) { /* already exists / no ALTER */ }
  }

  // Однократная миграция: старый users.prefix_id → user_prefix_map
  try {
    db()->exec(
      "INSERT IGNORE INTO user_prefix_map (user_id, prefix_id, sort_order)
       SELECT id, prefix_id, 0 FROM users
       WHERE prefix_id IS NOT NULL AND prefix_id > 0"
    );
  } catch (Throwable $e) {}
}

/** Парсинг DATETIME из БД в unix ts (учитывает date_default_timezone_set проекта). */
function user_session_ts($value): int {
  if ($value === null || $value === '' || $value === false) return 0;
  if (is_numeric($value)) return (int)$value;
  $t = strtotime((string)$value);
  return $t !== false ? $t : 0;
}

/**
 * Обновление «этой сессии» для текущего юзера.
 * Разрыв > 30 мин без last_seen = новая сессия.
 * Время пишем из PHP (date), не NOW() БД — иначе рассинхрон TZ ломает счётчик.
 */
function user_touch_session(?array $user): void {
  if (!$user || empty($user['id'])) return;
  user_display_ensure_schema();
  $uid = (int)$user['id'];
  $gap = 30 * 60;
  $now = time();
  $nowStr = date('Y-m-d H:i:s', $now);
  try {
    $st = db()->prepare('SELECT session_started_at, last_seen_at, session_seconds FROM users WHERE id = ?');
    $st->execute([$uid]);
    $row = $st->fetch() ?: [];
    $last = user_session_ts($row['last_seen_at'] ?? null);
    $start = user_session_ts($row['session_started_at'] ?? null);

    if (!$start || !$last || ($now - $last) > $gap) {
      db()->prepare('UPDATE users SET session_started_at = ?, last_seen_at = ?, session_seconds = 0 WHERE id = ?')
        ->execute([$nowStr, $nowStr, $uid]);
      return;
    }
    $sec = max(0, $now - $start);
    db()->prepare('UPDATE users SET last_seen_at = ?, session_seconds = ? WHERE id = ?')
      ->execute([$nowStr, $sec, $uid]);
  } catch (Throwable $e) {
    // колонок ещё нет / нет прав — тихо
  }
}

/**
 * Подпись сессии для профиля / мини-профиля.
 * Онлайн (last_seen ≤ 5 мин): «эта сессия: N мин» — считаем live от session_started_at.
 * Оффлайн: «последняя сессия: …» из session_seconds.
 */
function user_session_label_from_row(array $u): string {
  $last = user_session_ts($u['last_seen_at'] ?? null);
  $start = user_session_ts($u['session_started_at'] ?? null);
  $stored = (int)($u['session_seconds'] ?? 0);
  $now = time();
  $onlineGap = 5 * 60;

  // Онлайн — длительность текущей сессии live
  if ($start > 0 && $last > 0 && ($now - $last) <= $onlineGap) {
    $sec = max(0, $now - $start);
    // защита от мусора (часы в будущем и т.п.)
    if ($sec > 86400 * 7) $sec = $stored > 0 ? $stored : 0;
    return 'эта сессия: ' . user_format_duration($sec);
  }

  // Оффлайн, но есть записанная длительность
  if ($stored > 0) {
    return 'последняя сессия: ' . user_format_duration($stored);
  }

  // Была сессия, но seconds ещё 0 (только зашёл и ушёл)
  if ($start > 0 && $last > 0) {
    $sec = max(0, $last - $start);
    if ($sec > 0) return 'последняя сессия: ' . user_format_duration($sec);
  }

  return 'сессия: —';
}

/** Свежие session_* поля юзера из БД (после touch). */
function user_fetch_session_fields(int $userId): array {
  if ($userId <= 0) {
    return ['session_started_at' => null, 'last_seen_at' => null, 'session_seconds' => 0];
  }
  try {
    $st = db()->prepare('SELECT session_started_at, last_seen_at, session_seconds FROM users WHERE id = ?');
    $st->execute([$userId]);
    $row = $st->fetch() ?: [];
    return [
      'session_started_at' => $row['session_started_at'] ?? null,
      'last_seen_at' => $row['last_seen_at'] ?? null,
      'session_seconds' => (int)($row['session_seconds'] ?? 0),
    ];
  } catch (Throwable $e) {
    return ['session_started_at' => null, 'last_seen_at' => null, 'session_seconds' => 0];
  }
}

function user_format_duration(int $sec): string {
  if ($sec < 60) return 'меньше минуты';
  $min = (int)floor($sec / 60);
  if ($min < 60) return $min . ' мин';
  $h = (int)floor($min / 60);
  $m = $min % 60;
  if ($h < 48) return $h . ' ч ' . $m . ' мин';
  $d = (int)floor($h / 24);
  return $d . ' дн ' . ($h % 24) . ' ч';
}

function user_get_prefix(?int $prefixId): ?array {
  if (!$prefixId) return null;
  static $cache = [];
  if (array_key_exists($prefixId, $cache)) return $cache[$prefixId];
  try {
    $st = db()->prepare('SELECT * FROM user_prefixes WHERE id = ? AND is_active = 1');
    $st->execute([$prefixId]);
    $cache[$prefixId] = $st->fetch() ?: null;
  } catch (Throwable $e) {
    $cache[$prefixId] = null;
  }
  return $cache[$prefixId];
}

/**
 * Все активные префиксы пользователя (макс. 3), по sort_order.
 * Берёт из user_prefix_map; если пусто — fallback на users.prefix_id.
 */
function user_get_prefixes_for_user(array $user): array {
  user_display_ensure_schema();
  $uid = (int)($user['id'] ?? 0);
  $out = [];
  $seen = [];

  if ($uid > 0) {
    try {
      $st = db()->prepare(
        "SELECT p.* FROM user_prefix_map m
         JOIN user_prefixes p ON p.id = m.prefix_id AND p.is_active = 1
         WHERE m.user_id = ?
         ORDER BY m.sort_order ASC, p.sort_order ASC, p.id ASC
         LIMIT 3"
      );
      $st->execute([$uid]);
      foreach ($st->fetchAll() as $row) {
        $pid = (int)$row['id'];
        if (isset($seen[$pid])) continue;
        $seen[$pid] = true;
        $out[] = $row;
      }
    } catch (Throwable $e) {}
  }

  // fallback: старый одиночный prefix_id / денормализованные поля
  if (!$out) {
    if (!empty($user['prefix_id'])) {
      $p = user_get_prefix((int)$user['prefix_id']);
      if ($p) $out[] = $p;
    } elseif (!empty($user['prefix_title'])) {
      $out[] = [
        'title' => $user['prefix_title'],
        'css' => $user['prefix_css'] ?? '',
        'text_color' => $user['prefix_text_color'] ?? '#fff',
        'bg_color' => $user['prefix_bg_color'] ?? '#6366f1',
      ];
    }
  }

  return $out;
}

/**
 * Назначить пользователю до 3 префиксов (массив id).
 * Пустой массив / нули — снять все.
 */
function user_set_prefixes(int $userId, array $prefixIds): void {
  if ($userId <= 0) return;
  user_display_ensure_schema();

  $clean = [];
  $seen = [];
  foreach ($prefixIds as $pid) {
    $pid = (int)$pid;
    if ($pid <= 0 || isset($seen[$pid])) continue;
    $seen[$pid] = true;
    $clean[] = $pid;
    if (count($clean) >= 3) break;
  }

  try {
    db()->prepare('DELETE FROM user_prefix_map WHERE user_id = ?')->execute([$userId]);
    $ins = db()->prepare('INSERT INTO user_prefix_map (user_id, prefix_id, sort_order) VALUES (?, ?, ?)');
    foreach ($clean as $i => $pid) {
      $ins->execute([$userId, $pid, $i]);
    }
    // зеркало в старый столбец (первый префикс) для совместимости
    $first = $clean[0] ?? null;
    db()->prepare('UPDATE users SET prefix_id = ? WHERE id = ?')->execute([$first, $userId]);
  } catch (Throwable $e) {}
}

function user_render_prefix_html(?array $prefix): string {
  if (!$prefix) return '';
  $title = htmlspecialchars((string)$prefix['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $custom = trim((string)($prefix['css'] ?? ''));
  if ($custom !== '') {
    // только безопасный кусок css в style — без {} скриптов
    $custom = preg_replace('/[<>]|expression|javascript/i', '', $custom);
    return '<span class="user-prefix" style="' . htmlspecialchars($custom, ENT_QUOTES) . '">' . $title . '</span>';
  }
  $tc = htmlspecialchars((string)($prefix['text_color'] ?? '#fff'), ENT_QUOTES);
  $bg = htmlspecialchars((string)($prefix['bg_color'] ?? '#6366f1'), ENT_QUOTES);
  return '<span class="user-prefix" style="display:inline-block;padding:1px 7px;border-radius:6px;font-size:11px;font-weight:700;line-height:1.4;color:'
    . $tc . ';background:' . $bg . ';margin-right:4px;vertical-align:middle">' . $title . '</span>';
}

/** Несколько префиксов подряд */
function user_render_prefixes_html(array $prefixes): string {
  if (!$prefixes) return '';
  $parts = [];
  foreach ($prefixes as $p) {
    $h = user_render_prefix_html($p);
    if ($h !== '') $parts[] = $h;
  }
  if (!$parts) return '';
  return '<span class="user-prefixes">' . implode(' ', $parts) . '</span> ';
}

/** Безопасный CSS для ника пользователя */
function user_sanitize_nick_css(string $css): string {
  $css = strip_tags($css);
  $css = preg_replace('/[<>]|expression\s*\(|javascript:|@import|behavior/i', '', $css) ?? '';
  return mb_substr(trim($css), 0, 500);
}

function user_render_username_html(array $user): string {
  $name = htmlspecialchars((string)($user['username'] ?? 'Гость'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  // Если CSS не передали — дотянем из БД (форум/лента часто отдают только username+id)
  if ((!isset($user['username_css']) || $user['username_css'] === null || $user['username_css'] === '')
      && !empty($user['id'])) {
    try {
      $st = db()->prepare('SELECT username_css FROM users WHERE id = ? LIMIT 1');
      $st->execute([(int)$user['id']]);
      $got = $st->fetchColumn();
      if ($got !== false && $got !== null && (string)$got !== '') {
        $user['username_css'] = (string)$got;
      }
    } catch (Throwable $e) {}
  }
  $css = user_sanitize_nick_css((string)($user['username_css'] ?? ''));
  // inline style побеждает color родителя (.pg-name-text { color:#fff })
  $style = $css !== '' ? ' style="' . htmlspecialchars($css, ENT_QUOTES) . '"' : '';
  $prefixes = user_get_prefixes_for_user($user);
  return user_render_prefixes_html($prefixes) . '<span class="user-nick"' . $style . '>' . $name . '</span>';
}

/**
 * Мини-профиль под постом (XenForo / resource-world style).
 */
function user_render_mini_profile(array $user): string {
  $avatar = function_exists('user_avatar_url') ? user_avatar_url($user, 96) : '';
  $cover = (string)($user['profile_cover'] ?? $user['cover_url'] ?? $user['profile_cover_url'] ?? '');
  $status = (string)($user['profile_status'] ?? $user['status_text'] ?? $user['profile_status_text'] ?? '');
  $uname = (string)($user['username'] ?? '');
  $href = $uname !== '' ? '/profile?username=' . rawurlencode($uname) : '#';
  $coverStyle = $cover !== ''
    ? 'background-image:url(' . htmlspecialchars($cover, ENT_QUOTES) . ');background-size:cover;background-position:center;'
    : 'background:linear-gradient(135deg,#1e1b4b,#312e81);';

  $html = '<div class="xf-mini-profile">';
  $html .= '<div class="xf-mini-cover" style="' . $coverStyle . '"></div>';
  $html .= '<div class="xf-mini-body">';
  $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="xf-mini-avatar-link">';
  $html .= '<img src="' . htmlspecialchars($avatar, ENT_QUOTES) . '" alt="" class="xf-mini-avatar" loading="lazy">';
  $html .= '</a>';
  $html .= '<div class="xf-mini-meta">';
  $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="xf-mini-name">' . user_render_username_html($user) . '</a>';
  if ($status !== '') {
    $html .= '<div class="xf-mini-status">♪ ' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
  }
  if (!empty($user['role']) && $user['role'] === 'admin') {
    $html .= '<div class="xf-mini-role">админ</div>';
  }
  $html .= '</div></div></div>';
  return $html;
}
