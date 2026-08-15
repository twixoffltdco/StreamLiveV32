<?php
/** Платные / приватные темы форума (доступ по партнёрской подписке или промокоду) */
function forum_paid_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec("ALTER TABLE forum_threads ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0");
  } catch (Throwable $e) {}
}

function forum_thread_is_paid(array $thread): bool {
  return !empty($thread['is_paid']);
}

function forum_user_can_view_paid(?array $user, array $thread): bool {
  if (!forum_thread_is_paid($thread)) return true;
  if (!$user) return false;
  $uid = (int)($user['id'] ?? 0);
  if ($uid <= 0) return false;
  // автор / staff всегда
  if ((int)($thread['user_id'] ?? 0) === $uid) return true;
  if (in_array(($user['role'] ?? ''), ['admin', 'moderator'], true)) return true;
  if (function_exists('is_forum_moderator') && is_forum_moderator($user)) return true;
  // партнёрская подписка (промокод)
  if (is_file(__DIR__ . '/partners.php')) {
    try {
      require_once __DIR__ . '/partners.php';
      if (function_exists('partners_has_active_sub') && partners_has_active_sub($uid)) {
        return true;
      }
    } catch (Throwable $e) {}
  }
  // общий paid_activations
  foreach ([
    "SELECT 1 FROM paid_activations WHERE user_id=? AND expires_at > NOW() LIMIT 1",
    "SELECT 1 FROM promo_activations WHERE user_id=? AND expires_at > NOW() LIMIT 1",
  ] as $sql) {
    try {
      $st = db()->prepare($sql);
      $st->execute([$uid]);
      if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {}
  }
  return false;
}
