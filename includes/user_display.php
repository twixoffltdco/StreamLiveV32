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

  foreach ([
    'is_personal' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'owner_user_id' => 'INT UNSIGNED NULL',
    'is_system' => 'TINYINT(1) NOT NULL DEFAULT 0',
  ] as $col => $def) {
    try { db()->exec("ALTER TABLE user_prefixes ADD COLUMN `{$col}` {$def}"); } catch (Throwable $e) {}
  }

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
    'username_css' => 'MEDIUMTEXT NULL',
    'nick_decor_url' => 'VARCHAR(500) NULL',
    'nick_decor_pos' => "VARCHAR(10) NOT NULL DEFAULT 'before'",
    'custom_prefix_id' => 'INT UNSIGNED NULL',
    'custom_prefix_changed_at' => 'DATETIME NULL',
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

/** id пользователя: users.id ИЛИ forum_posts.user_id */
function user_resolve_id(array $user): int {
  $id = (int)($user['id'] ?? 0);
  if ($id > 0) return $id;
  return (int)($user['user_id'] ?? 0);
}


/**
 * Только официальные системные префиксы (созданные в админке).
 * Каналы и личные — никогда.
 */
function user_prefixes_system_list(bool $onlyActive = true): array {
  user_display_ensure_schema();
  // Снять is_system с названий каналов (мусор)
  try {
    $chTitles = [];
    foreach (db()->query("SELECT title FROM channels")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ct) {
      $k = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$ct)) : strtolower(trim((string)$ct));
      if ($k !== '') $chTitles[$k] = true;
    }
    foreach (db()->query("SELECT id, title FROM user_prefixes")->fetchAll() ?: [] as $r) {
      $tk = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($r['title'] ?? ''))) : strtolower(trim((string)($r['title'] ?? '')));
      if ($tk !== '' && !empty($chTitles[$tk])) {
        db()->prepare('UPDATE user_prefixes SET is_system=0 WHERE id=?')->execute([(int)$r['id']]);
      }
    }
  } catch (Throwable $e) {}
  $rows = [];
  try {
    // Предпочтительно is_system=1
    $sql = "SELECT * FROM user_prefixes WHERE COALESCE(is_system,0)=1 AND COALESCE(is_personal,0)=0
            AND (owner_user_id IS NULL OR owner_user_id=0)";
    if ($onlyActive) $sql .= " AND is_active=1";
    $sql .= " ORDER BY sort_order ASC, id ASC";
    $rows = db()->query($sql)->fetchAll() ?: [];
  } catch (Throwable $e) {
    $rows = [];
  }
  // Если is_system ещё ни у кого не проставлен — fallback: не personal + не title канала
  if (!$rows) {
    try {
      $sql = "SELECT * FROM user_prefixes WHERE COALESCE(is_personal,0)=0 AND (owner_user_id IS NULL OR owner_user_id=0)";
      if ($onlyActive) $sql .= " AND is_active=1";
      $sql .= " ORDER BY sort_order ASC, id ASC";
      $all = db()->query($sql)->fetchAll() ?: [];
    } catch (Throwable $e) {
      $all = [];
    }
    $ch = [];
    try {
      foreach (db()->query("SELECT title FROM channels")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ct) {
        $k = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$ct)) : strtolower(trim((string)$ct));
        if ($k !== '') $ch[$k] = true;
      }
    } catch (Throwable $e) {}
    foreach ($all as $r) {
      $tk = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($r['title'] ?? ''))) : strtolower(trim((string)($r['title'] ?? '')));
      if ($tk !== '' && !empty($ch[$tk])) continue;
      $rows[] = $r;
    }
  } else {
    // даже с is_system — выкинуть title=канал на всякий
    $ch = [];
    try {
      foreach (db()->query("SELECT title FROM channels")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ct) {
        $k = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$ct)) : strtolower(trim((string)$ct));
        if ($k !== '') $ch[$k] = true;
      }
    } catch (Throwable $e) {}
    $rows = array_values(array_filter($rows, static function ($r) use ($ch) {
      $tk = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($r['title'] ?? ''))) : strtolower(trim((string)($r['title'] ?? '')));
      return $tk === '' || empty($ch[$tk]);
    }));
  }
  return $rows;
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
  $uid = user_resolve_id($user);
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
        $titleKey = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($row['title'] ?? ''))) : strtolower(trim((string)($row['title'] ?? '')));
        if ($titleKey !== '' && isset($seen['t:' . $titleKey])) continue;
        $seen[$pid] = true;
        if ($titleKey !== '') $seen['t:' . $titleKey] = true;
        $out[] = $row;
      }
    } catch (Throwable $e) {}

    // свой префикс пользователя (custom_prefix_id) — всегда в начале, если активен
    $customId = (int)($user['custom_prefix_id'] ?? 0);
    if ($customId <= 0) {
      try {
        $st = db()->prepare('SELECT custom_prefix_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $customId = (int)($st->fetchColumn() ?: 0);
      } catch (Throwable $e) {}
    }
    if ($customId > 0 && empty($seen[$customId])) {
      $p = user_get_prefix($customId);
      if ($p) {
        $titleKey = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($p['title'] ?? ''))) : strtolower(trim((string)($p['title'] ?? '')));
        if ($titleKey === '' || empty($seen['t:' . $titleKey])) {
          array_unshift($out, $p);
          $seen[$customId] = true;
          if ($titleKey !== '') $seen['t:' . $titleKey] = true;
          $out = array_slice($out, 0, 3);
        }
      }
    }
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
  $seenTitles = [];
  foreach ($prefixes as $p) {
    $tk = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($p['title'] ?? ''))) : strtolower(trim((string)($p['title'] ?? '')));
    if ($tk !== '' && isset($seenTitles[$tk])) continue;
    if ($tk !== '') $seenTitles[$tk] = true;
    $h = user_render_prefix_html($p);
    if ($h !== '') $parts[] = $h;
    if (count($parts) >= 3) break;
  }
  if (!$parts) return '';
  return '<span class="user-prefixes">' . implode('', $parts) . '</span>';
}

/** Безопасный CSS для ника: inline ИЛИ блок с @keyframes / классами */
function user_sanitize_nick_css(string $css): string {
  $css = strip_tags($css);
  $css = preg_replace('/[<>]|expression\s*\(|javascript\s*:|@import|behavior\s*:|binding\s*:|-moz-binding/i', '', $css) ?? '';
  // url() только http(s) или data:image
  $css = preg_replace_callback('/url\s*\(\s*[\'"]?([^)\'"]+)[\'"]?\s*\)/i', static function ($m) {
    $u = trim($m[1]);
    if (preg_match('#^(https?:|data:image/)#i', $u)) {
      return 'url(' . $u . ')';
    }
    return 'url()';
  }, $css) ?? $css;
  $css = trim($css);
  return function_exists('mb_substr') ? mb_substr($css, 0, 8000) : substr($css, 0, 8000);
}

function user_nick_css_is_block(string $css): bool {
  return (bool)preg_match('/@keyframes|\.[a-zA-Z_][\w-]*\s*\{/s', $css);
}

/**
 * Готовит CSS-блок: уникальные @keyframes на юзера, список классов для span.
 * @return array{css:string,classes:string[]}
 */
function user_prepare_nick_css_block(string $css, int $uid): array {
  $css = user_sanitize_nick_css($css);
  $pfx = 'u' . $uid . '_';
  // @keyframes foo → @keyframes u12_foo
  $css = preg_replace('/@keyframes\s+([a-zA-Z_][\w-]*)/', '@keyframes ' . $pfx . '$1', $css) ?? $css;
  // animation-name: foo
  $css = preg_replace('/animation-name\s*:\s*([a-zA-Z_][\w-]*)/i', 'animation-name: ' . $pfx . '$1', $css) ?? $css;
  // animation: foo 1.5s infinite → animation: u12_foo 1.5s infinite
  $reserved = ['infinite','linear','ease','ease-in','ease-out','ease-in-out','alternate','alternate-reverse','forwards','backwards','both','normal','reverse','none','paused','running','step-start','step-end'];
  $css = preg_replace_callback('/animation\s*:\s*([^;{}]+)/i', static function ($m) use ($pfx, $reserved) {
    $parts = preg_split('/(\s+)/', trim($m[1]), -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = [];
    $renamed = false;
    foreach ($parts as $p) {
      if ($p === '' || preg_match('/^\s+$/', $p) || preg_match('/^[\d.]+m?s$/i', $p) || preg_match('/^[\d.]+$/', $p)) {
        $out[] = $p;
        continue;
      }
      if (!$renamed && preg_match('/^[a-zA-Z_][\w-]*$/', $p) && !in_array(strtolower($p), $reserved, true)) {
        $out[] = $pfx . $p;
        $renamed = true;
      } else {
        $out[] = $p;
      }
    }
    return 'animation: ' . implode('', $out);
  }, $css) ?? $css;

  $classes = [];
  if (preg_match_all('/\.([a-zA-Z_][\w-]*)\s*\{/', $css, $mm)) {
    foreach ($mm[1] as $c) {
      if (!in_array($c, $classes, true)) $classes[] = $c;
    }
  }
  $uniq = 'unick-' . $uid;
  if (!$classes) {
    $css .= "\n." . $uniq . "{display:inline-block;}";
    $classes[] = $uniq;
  }
  return ['css' => $css, 'classes' => $classes, 'uniq' => $uniq];
}

/** Декорация ника (gif/png) */
function user_render_nick_decor(?array $user, string $where): string {
  if (!$user) return '';
  $url = trim((string)($user['nick_decor_url'] ?? ''));
  if ($url === '' && !empty($user['id'])) {
    try {
      $st = db()->prepare('SELECT nick_decor_url, nick_decor_pos FROM users WHERE id = ? LIMIT 1');
      $st->execute([(int)$user['id']]);
      $row = $st->fetch() ?: [];
      $url = trim((string)($row['nick_decor_url'] ?? ''));
      if (!isset($user['nick_decor_pos'])) $user['nick_decor_pos'] = $row['nick_decor_pos'] ?? 'before';
    } catch (Throwable $e) {}
  }
  if ($url === '' || !preg_match('#^https?://#i', $url)) return '';
  $pos = strtolower((string)($user['nick_decor_pos'] ?? 'before'));
  if ($pos !== 'after') $pos = 'before';
  if ($where !== $pos) return '';
  $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return '<img class="user-nick-decor" src="' . $safe . '" alt="" loading="lazy" style="height:22px;width:auto;vertical-align:middle;margin:0 3px;display:inline-block">';
}

function user_render_username_html(array $user, bool $withPrefixes = true): string {
  $name = htmlspecialchars((string)($user['username'] ?? 'Гость'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $uid = function_exists('user_resolve_id') ? user_resolve_id($user) : (int)($user['id'] ?? $user['user_id'] ?? 0);

  // дотянуть css/decor из БД
  if ($uid > 0 && (!isset($user['username_css']) || $user['username_css'] === null || trim((string)$user['username_css']) === '')) {
    try {
      $st = db()->prepare('SELECT username_css, nick_decor_url, nick_decor_pos FROM users WHERE id = ? LIMIT 1');
      $st->execute([$uid]);
      $got = $st->fetch() ?: [];
      if (!empty($got['username_css'])) $user['username_css'] = (string)$got['username_css'];
      if (!empty($got['nick_decor_url'])) $user['nick_decor_url'] = (string)$got['nick_decor_url'];
      if (!empty($got['nick_decor_pos'])) $user['nick_decor_pos'] = (string)$got['nick_decor_pos'];
    } catch (Throwable $e) {}
  }

  $rawCss = trim((string)($user['username_css'] ?? ''));
  $css = user_sanitize_nick_css($rawCss);
  $styleTag = '';
  $classAttr = 'user-nick';
  $styleAttr = '';

  // Голый hex / rgb → color
  if ($css !== '' && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $css)) {
    $css = 'color: ' . $css;
  } elseif ($css !== '' && preg_match('/^rgba?\([^)]+\)$/i', $css)) {
    $css = 'color: ' . $css;
  }

  if ($css !== '' && $uid > 0 && user_nick_css_is_block($css)) {
    $prep = user_prepare_nick_css_block($css, $uid);
    static $emittedStyles = [];
    if (empty($emittedStyles[$uid])) {
      $emittedStyles[$uid] = true;
      // усиливаем цвет ника против тем сайта
      $boost = '.' . $prep['uniq'] . ',.' . $prep['uniq'] . ' .user-nick{color:inherit}';
      $styleTag = '<style data-unick="' . $uid . '">' . $prep['css'] . $boost . '</style>';
    }
    $cls = array_merge(['user-nick', $prep['uniq']], $prep['classes']);
    $classAttr = htmlspecialchars(implode(' ', array_unique($cls)), ENT_QUOTES);
  } elseif ($css !== '') {
    if (strpos($css, '{') === false && strpos($css, '@') === false) {
      // Простые свойства: color / text-shadow с !important, чтобы тема не затирала
      $parts = array_filter(array_map('trim', explode(';', $css)));
      $out = [];
      foreach ($parts as $part) {
        if ($part === '') continue;
        if (preg_match('/^(color|text-shadow|background|background-image|background-clip|-webkit-background-clip|background-size|filter|font-weight|letter-spacing|text-fill-color|-webkit-text-fill-color)\s*:/i', $part)
            && stripos($part, '!important') === false) {
          $part .= ' !important';
        }
        $out[] = $part;
      }
      $cssInline = implode('; ', $out);
      $styleAttr = ' style="' . htmlspecialchars($cssInline, ENT_QUOTES) . '"';
    } else {
      $fakeUid = $uid > 0 ? $uid : (crc32($name) & 0x7fffffff);
      $prep = user_prepare_nick_css_block($css, $fakeUid);
      $styleTag = '<style data-unick="' . $fakeUid . '">' . $prep['css'] . '</style>';
      $cls = array_merge(['user-nick', $prep['uniq']], $prep['classes']);
      $classAttr = htmlspecialchars(implode(' ', array_unique($cls)), ENT_QUOTES);
    }
  }

  $prefixes = $withPrefixes ? user_get_prefixes_for_user($user) : [];
  $decorBefore = user_render_nick_decor($user, 'before');
  $decorAfter = user_render_nick_decor($user, 'after');

  return $styleTag
    . user_render_prefixes_html($prefixes)
    . $decorBefore
    . '<span class="' . $classAttr . '"' . $styleAttr . '>' . $name . '</span>'
    . $decorAfter;
}

/** Можно ли юзеру сменить/создать свой префикс (раз в 30 дней) */
function user_can_edit_custom_prefix(array $user): bool {
  $at = $user['custom_prefix_changed_at'] ?? null;
  if ($at === null || $at === '') {
    if (empty($user['id'])) return true;
    try {
      $st = db()->prepare('SELECT custom_prefix_changed_at FROM users WHERE id = ?');
      $st->execute([(int)$user['id']]);
      $at = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { return true; }
  }
  if (!$at) return true;
  $ts = strtotime((string)$at);
  if (!$ts) return true;
  return (time() - $ts) >= 30 * 86400;
}

function user_custom_prefix_next_date(array $user): ?string {
  $at = $user['custom_prefix_changed_at'] ?? null;
  if (!$at && !empty($user['id'])) {
    try {
      $st = db()->prepare('SELECT custom_prefix_changed_at FROM users WHERE id = ?');
      $st->execute([(int)$user['id']]);
      $at = $st->fetchColumn() ?: null;
    } catch (Throwable $e) {}
  }
  if (!$at) return null;
  $ts = strtotime((string)$at);
  if (!$ts) return null;
  $next = $ts + 30 * 86400;
  if ($next <= time()) return null;
  return date('d.m.Y H:i', $next);
}

/**
 * Создать/обновить свой префикс (1 шт, не чаще раза в 30 дней).
 * Возвращает ['ok'=>bool,'error'=>string|null]
 */
function user_save_custom_prefix(int $userId, string $title, string $textColor, string $bgColor, string $css = ''): array {
  if ($userId <= 0) return ['ok' => false, 'error' => 'Нет пользователя'];
  user_display_ensure_schema();
  $title = mb_substr(trim($title), 0, 40);
  if ($title === '') return ['ok' => false, 'error' => 'Укажите название префикса'];
  $textColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', $textColor) ? $textColor : '#ffffff';
  $bgColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', $bgColor) ? $bgColor : '#6366f1';
  $css = user_sanitize_nick_css($css);
  if (mb_strlen($css) > 500) $css = mb_substr($css, 0, 500);

  try {
    $st = db()->prepare('SELECT custom_prefix_id, custom_prefix_changed_at FROM users WHERE id = ?');
    $st->execute([$userId]);
    $row = $st->fetch() ?: [];
    if (!user_can_edit_custom_prefix(['custom_prefix_changed_at' => $row['custom_prefix_changed_at'] ?? null, 'id' => $userId])) {
      $next = user_custom_prefix_next_date(['custom_prefix_changed_at' => $row['custom_prefix_changed_at'] ?? null]);
      return ['ok' => false, 'error' => 'Свой префикс можно менять раз в 30 дней' . ($next ? ' (с ' . $next . ')' : '')];
    }
    $oldId = (int)($row['custom_prefix_id'] ?? 0);
    if ($oldId > 0) {
      db()->prepare('UPDATE user_prefixes SET title=?, css=?, text_color=?, bg_color=?, is_active=1 WHERE id=?')
        ->execute([$title, $css !== '' ? $css : null, $textColor, $bgColor, $oldId]);
      $pid = $oldId;
    } else {
      db()->prepare('INSERT INTO user_prefixes (title, css, text_color, bg_color, sort_order, is_active, is_personal, owner_user_id) VALUES (?,?,?,?,0,1,1,?)')
        ->execute([$title, $css !== '' ? $css : null, $textColor, $bgColor, $userId]);
      $pid = (int)db()->lastInsertId();
    }
    db()->prepare('UPDATE users SET custom_prefix_id = ?, custom_prefix_changed_at = ?, prefix_id = ? WHERE id = ?')
      ->execute([$pid, date('Y-m-d H:i:s'), $pid, $userId]);
    // map: свой префикс = первый слот, официальные не трогаем сверх лимита — заменим только custom в map
    try {
      db()->prepare('DELETE FROM user_prefix_map WHERE user_id = ? AND prefix_id = ?')->execute([$userId, $pid]);
      // поставить custom первым
      $existing = db()->prepare('SELECT prefix_id FROM user_prefix_map WHERE user_id = ? ORDER BY sort_order');
      $existing->execute([$userId]);
      $ids = array_map('intval', array_column($existing->fetchAll() ?: [], 'prefix_id'));
      $ids = array_values(array_filter($ids, static fn($x) => $x !== $pid));
      array_unshift($ids, $pid);
      $ids = array_slice($ids, 0, 3);
      user_set_prefixes($userId, $ids);
    } catch (Throwable $e) {}
    return ['ok' => true, 'error' => null, 'prefix_id' => $pid];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'Не удалось сохранить префикс'];
  }
}


function user_render_mini_profile(array $user): string {
  $uid = function_exists('user_resolve_id') ? user_resolve_id($user) : (int)($user['id'] ?? $user['user_id'] ?? 0);
  if ($uid > 0 && empty($user['id'])) $user['id'] = $uid;
  // дотянуть поля, которые форум часто не отдаёт
  if ($uid > 0) {
    $need = empty($user['username_css']) || empty($user['profile_cover_url']) || empty($user['profile_status_text'])
         || empty($user['custom_prefix_id']) || empty($user['nick_decor_url']) || !isset($user['is_verified']);
    if ($need) {
      try {
        $st = db()->prepare(
          'SELECT username_css, profile_cover_url, profile_status_text, nick_decor_url, nick_decor_pos,
                  custom_prefix_id, prefix_id, is_verified, role, avatar
           FROM users WHERE id = ? LIMIT 1'
        );
        $st->execute([$uid]);
        $row = $st->fetch() ?: [];
        foreach ($row as $k => $v) {
          if (!isset($user[$k]) || $user[$k] === null || $user[$k] === '') {
            $user[$k] = $v;
          }
        }
      } catch (Throwable $e) {
        try {
          $st = db()->prepare('SELECT username_css, prefix_id, custom_prefix_id, is_verified FROM users WHERE id = ? LIMIT 1');
          $st->execute([$uid]);
          foreach (($st->fetch() ?: []) as $k => $v) {
            if (!isset($user[$k]) || $user[$k] === null || $user[$k] === '') $user[$k] = $v;
          }
        } catch (Throwable $e2) {}
      }
    }
  }

  $avatar = function_exists('user_avatar_url') ? user_avatar_url($user, 96) : '';
  $cover = (string)($user['profile_cover_url'] ?? $user['profile_cover'] ?? $user['cover_url'] ?? '');
  $status = (string)($user['profile_status_text'] ?? $user['profile_status'] ?? $user['status_text'] ?? '');
  $uname = (string)($user['username'] ?? '');
  $href = $uname !== '' ? '/profile?username=' . rawurlencode($uname) : '#';
  $coverStyle = $cover !== ''
    ? 'background-image:url(' . htmlspecialchars($cover, ENT_QUOTES) . ');background-size:cover;background-position:center;'
    : 'background:linear-gradient(135deg,#1e1b4b 0%,#4c1d95 50%,#0f172a 100%);';

  $html = '<div class="xf-mini-profile">';
  $html .= '<div class="xf-mini-cover" style="' . $coverStyle . '"></div>';
  $html .= '<div class="xf-mini-body">';
  $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="xf-mini-avatar-link">';
  $html .= '<img src="' . htmlspecialchars($avatar, ENT_QUOTES) . '" alt="" class="xf-mini-avatar" loading="lazy">';
  $html .= '</a>';
  $html .= '<div class="xf-mini-meta">';
  $html .= '<div class="xf-mini-name-row">';
  $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="xf-mini-name">' . user_render_username_html($user, false) . '</a>';
  if (!empty($user['is_verified']) && function_exists('verify_badge')) {
    $html .= verify_badge(true);
  }
  $html .= '</div>';
  if ($status !== '') {
    $html .= '<div class="xf-mini-status">♪ ' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
  }
  $role = (string)($user['role'] ?? '');
  if ($role === 'admin') {
    $html .= '<div class="xf-mini-role">админ</div>';
  } elseif ($role === 'moderator') {
    $html .= '<div class="xf-mini-role">модератор</div>';
  }
  $html .= '<a class="xf-mini-open" href="' . htmlspecialchars($href, ENT_QUOTES) . '">Открыть профиль →</a>';
  $html .= '</div></div></div>';
  return $html;
}


/** Компактный бейдж для API/JSON (HTML-строка) */
function user_badge_html_compact(array $user, int $size = 22): string {
  if (empty($user['id']) && !empty($user['user_id'])) {
    $user['id'] = (int)$user['user_id'];
  }
  if (function_exists('render_user_badge')) {
    return render_user_badge($user, $size, true);
  }
  return htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
