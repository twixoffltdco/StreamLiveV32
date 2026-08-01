<?php
/**
 * Префиксы (XenForo-style), CSS ника, сессия на платформе, мини-профиль.
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
}

/** Обновление «этой сессии» для текущего юзера (разрыв > 30 мин = новая сессия). */
function user_touch_session(?array $user): void {
  if (!$user || empty($user['id'])) return;
  user_display_ensure_schema();
  $uid = (int)$user['id'];
  $gap = 30 * 60; // 30 минут бездействия → новая сессия
  try {
    $st = db()->prepare('SELECT session_started_at, last_seen_at, session_seconds FROM users WHERE id = ?');
    $st->execute([$uid]);
    $row = $st->fetch() ?: [];
    $now = time();
    $last = !empty($row['last_seen_at']) ? strtotime($row['last_seen_at']) : 0;
    $start = !empty($row['session_started_at']) ? strtotime($row['session_started_at']) : 0;

    if (!$start || !$last || ($now - $last) > $gap) {
      // новая сессия
      db()->prepare('UPDATE users SET session_started_at = NOW(), last_seen_at = NOW(), session_seconds = 0 WHERE id = ?')
        ->execute([$uid]);
      return;
    }
    $sec = max(0, $now - $start);
    db()->prepare('UPDATE users SET last_seen_at = NOW(), session_seconds = ? WHERE id = ?')
      ->execute([$sec, $uid]);
  } catch (Throwable $e) {}
}

function user_session_label_from_row(array $u): string {
  $last = !empty($u['last_seen_at']) ? strtotime($u['last_seen_at']) : 0;
  $start = !empty($u['session_started_at']) ? strtotime($u['session_started_at']) : 0;
  $now = time();
  $onlineGap = 5 * 60;

  if ($start && $last && ($now - $last) <= $onlineGap) {
    $sec = max(0, $now - $start);
  } elseif (!empty($u['session_seconds'])) {
    $sec = (int)$u['session_seconds'];
    if ($last && ($now - $last) > $onlineGap) {
      // оффлайн — показываем длительность последней сессии
      return 'последняя сессия: ' . user_format_duration($sec);
    }
  } else {
    return 'сессия: —';
  }
  return 'эта сессия: ' . user_format_duration($sec);
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

function user_render_prefix_html(?array $prefix): string {
  if (!$prefix) return '';
  $title = htmlspecialchars((string)$prefix['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $custom = trim((string)($prefix['css'] ?? ''));
  if ($custom !== '') {
    // только безопасный кусок css в style — без {} скриптов
    $custom = preg_replace('/[<>]|expression|javascript/i', '', $custom);
    return '<span class="user-prefix" style="' . htmlspecialchars($custom, ENT_QUOTES) . '">' . $title . '</span> ';
  }
  $tc = htmlspecialchars((string)($prefix['text_color'] ?? '#fff'), ENT_QUOTES);
  $bg = htmlspecialchars((string)($prefix['bg_color'] ?? '#6366f1'), ENT_QUOTES);
  return '<span class="user-prefix" style="display:inline-block;padding:1px 7px;border-radius:6px;font-size:11px;font-weight:700;line-height:1.4;color:'
    . $tc . ';background:' . $bg . ';margin-right:4px;vertical-align:middle">' . $title . '</span> ';
}

/** Безопасный CSS для ника пользователя */
function user_sanitize_nick_css(string $css): string {
  $css = strip_tags($css);
  $css = preg_replace('/[<>]|expression\s*\(|javascript:|@import|behavior/i', '', $css) ?? '';
  return mb_substr(trim($css), 0, 500);
}

function user_render_username_html(array $user): string {
  $name = htmlspecialchars((string)($user['username'] ?? 'Гость'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $css = user_sanitize_nick_css((string)($user['username_css'] ?? ''));
  $style = $css !== '' ? ' style="' . htmlspecialchars($css, ENT_QUOTES) . '"' : '';
  $prefix = null;
  if (!empty($user['prefix_id'])) {
    $prefix = user_get_prefix((int)$user['prefix_id']);
  } elseif (!empty($user['prefix_title'])) {
    $prefix = [
      'title' => $user['prefix_title'],
      'css' => $user['prefix_css'] ?? '',
      'text_color' => $user['prefix_text_color'] ?? '#fff',
      'bg_color' => $user['prefix_bg_color'] ?? '#6366f1',
    ];
  }
  return user_render_prefix_html($prefix) . '<span class="user-nick"' . $style . '>' . $name . '</span>';
}

/**
 * Мини-профиль под постом (XenForo / resource-world style).
 */
function user_render_mini_profile(array $user): string {
  $avatar = function_exists('user_avatar_url') ? user_avatar_url($user, 96) : '';
  $cover = (string)($user['profile_cover'] ?? $user['cover_url'] ?? '');
  $status = (string)($user['profile_status'] ?? $user['status_text'] ?? '');
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
