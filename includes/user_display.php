<?php
/**
 * Префиксы XenForo-style, CSS ника, сессия, мини-профиль.
 * Production: колонки/таблицы создаются сами, SELECT падает мягко.
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
      text_color VARCHAR(32) DEFAULT '#ffffff',
      bg_color VARCHAR(32) DEFAULT '#6366f1',
      sort_order INT NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) {}

  $cols = [
    'prefix_id' => 'INT UNSIGNED NULL DEFAULT NULL',
    'username_css' => 'VARCHAR(500) NULL DEFAULT NULL',
    'session_started_at' => 'DATETIME NULL',
    'last_seen_at' => 'DATETIME NULL',
    'session_seconds' => 'INT UNSIGNED NOT NULL DEFAULT 0',
  ];
  foreach ($cols as $col => $def) {
    try {
      if (function_exists('table_column_exists') && table_column_exists('users', $col)) continue;
      db()->exec("ALTER TABLE users ADD COLUMN `{$col}` {$def}");
    } catch (Throwable $e) {}
  }

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS verification_requests (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      real_name VARCHAR(150) NOT NULL DEFAULT '',
      reason TEXT NOT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      reviewed_by INT NULL,
      review_note VARCHAR(500) NULL,
      reviewed_at DATETIME NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY (user_id), KEY (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) {}
}

function user_touch_session(?array $user): void {
  if (!$user || empty($user['id'])) return;
  try {
    user_display_ensure_schema();
    $uid = (int)$user['id'];
    $gap = 30 * 60;
    $st = db()->prepare('SELECT session_started_at, last_seen_at FROM users WHERE id = ?');
    $st->execute([$uid]);
    $row = $st->fetch() ?: [];
    $now = time();
    $last = !empty($row['last_seen_at']) ? (int)strtotime($row['last_seen_at']) : 0;
    $start = !empty($row['session_started_at']) ? (int)strtotime($row['session_started_at']) : 0;
    if (!$start || !$last || ($now - $last) > $gap) {
      db()->prepare('UPDATE users SET session_started_at = NOW(), last_seen_at = NOW(), session_seconds = 0 WHERE id = ?')->execute([$uid]);
      return;
    }
    db()->prepare('UPDATE users SET last_seen_at = NOW(), session_seconds = ? WHERE id = ?')
      ->execute([max(0, $now - $start), $uid]);
  } catch (Throwable $e) {}
}

function user_format_duration(int $sec): string {
  if ($sec < 60) return 'меньше минуты';
  $min = (int)floor($sec / 60);
  if ($min < 60) return $min . ' мин';
  $h = (int)floor($min / 60);
  $m = $min % 60;
  return $h . ' ч ' . $m . ' мин';
}

function user_session_label_from_row(array $u): string {
  $last = !empty($u['last_seen_at']) ? (int)strtotime($u['last_seen_at']) : 0;
  $start = !empty($u['session_started_at']) ? (int)strtotime($u['session_started_at']) : 0;
  $now = time();
  if ($start && $last && ($now - $last) <= 300) {
    return 'эта сессия: ' . user_format_duration(max(0, $now - $start));
  }
  if (!empty($u['session_seconds'])) {
    return 'последняя сессия: ' . user_format_duration((int)$u['session_seconds']);
  }
  return 'сессия: —';
}

function user_get_prefix(?int $prefixId): ?array {
  if (!$prefixId) return null;
  static $cache = [];
  if (array_key_exists($prefixId, $cache)) return $cache[$prefixId];
  try {
    $st = db()->prepare('SELECT * FROM user_prefixes WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$prefixId]);
    $cache[$prefixId] = $st->fetch() ?: null;
  } catch (Throwable $e) {
    $cache[$prefixId] = null;
  }
  return $cache[$prefixId];
}

/** Подтянуть prefix_id / username_css если в массиве их нет */
function user_enrich_display_fields(array $user): array {
  if (empty($user['id']) && empty($user['username'])) return $user;
  $need = !isset($user['prefix_id']) || !array_key_exists('username_css', $user);
  if (!$need && !empty($user['prefix_id']) && empty($user['_prefix_loaded'])) {
    // ok
  }
  if ($need || (!empty($user['prefix_id']) && empty($user['prefix_title']))) {
    try {
      if (!empty($user['id'])) {
        $st = db()->prepare('SELECT prefix_id, username_css FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$user['id']]);
      } else {
        $st = db()->prepare('SELECT id, prefix_id, username_css FROM users WHERE username = ? LIMIT 1');
        $st->execute([(string)$user['username']]);
      }
      $row = $st->fetch();
      if ($row) {
        $user['prefix_id'] = $row['prefix_id'] ?? ($user['prefix_id'] ?? null);
        $user['username_css'] = $row['username_css'] ?? ($user['username_css'] ?? null);
        if (!empty($row['id'])) $user['id'] = $row['id'];
      }
    } catch (Throwable $e) {}
  }
  return $user;
}

function user_render_prefix_html(?array $prefix): string {
  if (!$prefix || empty($prefix['title'])) return '';
  $title = htmlspecialchars((string)$prefix['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $custom = trim((string)($prefix['css'] ?? ''));
  if ($custom !== '') {
    $custom = preg_replace('/[<>]|expression|javascript|@import/i', '', $custom) ?? '';
    return '<span class="user-prefix user-prefix--custom" style="' . htmlspecialchars($custom, ENT_QUOTES) . '">' . $title . '</span>';
  }
  $tc = htmlspecialchars((string)($prefix['text_color'] ?: '#ffffff'), ENT_QUOTES);
  $bg = htmlspecialchars((string)($prefix['bg_color'] ?: '#6366f1'), ENT_QUOTES);
  return '<span class="user-prefix" style="color:' . $tc . ';background:' . $bg . '">' . $title . '</span>';
}

function user_sanitize_nick_css(string $css): string {
  $css = strip_tags($css);
  $css = preg_replace('/[<>]|expression\s*\(|javascript:|@import|behavior/i', '', $css) ?? '';
  return function_exists('mb_substr') ? mb_substr(trim($css), 0, 500) : substr(trim($css), 0, 500);
}

function user_render_username_html(array $user): string {
  $user = user_enrich_display_fields($user);
  $name = htmlspecialchars((string)($user['username'] ?? 'Гость'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $css = user_sanitize_nick_css((string)($user['username_css'] ?? ''));
  $style = $css !== '' ? ' style="' . htmlspecialchars($css, ENT_QUOTES) . '"' : '';

  $prefixHtml = '';
  $pid = isset($user['prefix_id']) ? (int)$user['prefix_id'] : 0;
  if ($pid > 0) {
    $prefixHtml = user_render_prefix_html(user_get_prefix($pid));
  }

  return $prefixHtml . '<span class="user-nick"' . $style . '>' . $name . '</span>';
}

function user_render_mini_profile(array $user): string {
  $user = user_enrich_display_fields($user);
  $avatar = function_exists('user_avatar_url') ? user_avatar_url($user, 96) : '';
  $cover = (string)($user['profile_cover'] ?? '');
  $status = (string)($user['profile_status'] ?? '');
  $uname = (string)($user['username'] ?? '');
  $href = $uname !== '' ? '/profile?username=' . rawurlencode($uname) : '#';
  $coverStyle = $cover !== ''
    ? 'background-image:url(' . htmlspecialchars($cover, ENT_QUOTES) . ');background-size:cover;background-position:center;'
    : 'background:linear-gradient(135deg,#1e1b4b,#312e81);';

  $html = '<div class="xf-mini-profile">';
  $html .= '<div class="xf-mini-cover" style="' . $coverStyle . '"></div>';
  $html .= '<div class="xf-mini-body">';
  $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '"><img src="' . htmlspecialchars($avatar, ENT_QUOTES) . '" class="xf-mini-avatar" alt="" loading="lazy"></a>';
  $html .= '<div class="xf-mini-meta"><a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="xf-mini-name">' . user_render_username_html($user) . '</a>';
  if ($status !== '') {
    $html .= '<div class="xf-mini-status">♪ ' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
  }
  $html .= '</div></div></div>';
  return $html;
}
