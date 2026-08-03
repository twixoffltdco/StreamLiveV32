<?php
/**
 * Production forum engine — лайки/подписки/жалобы/правки/прочитанное.
 * Миграция: sql/migrations/041_forum_engine_xf.sql (+ self-heal здесь).
 */

function forum_engine_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    $pdo = db();
  } catch (Throwable $e) {
    return;
  }

  $tables = [
    "CREATE TABLE IF NOT EXISTS forum_post_likes (
      post_id INT NOT NULL,
      user_id INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (post_id, user_id),
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS forum_thread_watch (
      thread_id INT NOT NULL,
      user_id INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (thread_id, user_id),
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS forum_thread_reads (
      thread_id INT NOT NULL,
      user_id INT NOT NULL,
      last_post_id INT NOT NULL DEFAULT 0,
      last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (thread_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS forum_post_reports (
      id INT AUTO_INCREMENT PRIMARY KEY,
      post_id INT NOT NULL,
      reporter_id INT NOT NULL,
      reason VARCHAR(255) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      status ENUM('open','closed') NOT NULL DEFAULT 'open',
      KEY idx_status (status),
      KEY idx_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS forum_thread_prefixes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      category_id INT NULL,
      title VARCHAR(64) NOT NULL,
      css VARCHAR(255) NULL,
      sort_order INT NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  ];
  foreach ($tables as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) {}
  }
  foreach ([
    "ALTER TABLE forum_posts ADD COLUMN updated_at DATETIME NULL",
    "ALTER TABLE forum_posts ADD COLUMN edited_by INT NULL",
    "ALTER TABLE forum_posts ADD COLUMN like_count INT NOT NULL DEFAULT 0",
    "ALTER TABLE forum_threads ADD COLUMN reply_count INT NOT NULL DEFAULT 0",
    "ALTER TABLE forum_threads ADD COLUMN prefix_id INT NULL",
    "ALTER TABLE forum_threads ADD COLUMN last_post_user_id INT NULL",
  ] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) {}
  }
}

function forum_is_mod(?array $user): bool {
  if (!$user) return false;
  if (($user['role'] ?? '') === 'admin') return true;
  return function_exists('is_forum_moderator') && is_forum_moderator($user);
}

function forum_post_like_count(int $postId): int {
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM forum_post_likes WHERE post_id = ?');
    $st->execute([$postId]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

function forum_post_like_toggle(int $postId, int $userId): array {
  forum_engine_ensure();
  if ($postId <= 0 || $userId <= 0) {
    return ['ok' => false, 'error' => 'bad ids'];
  }

  // пост должен существовать
  try {
    $st = db()->prepare('SELECT id FROM forum_posts WHERE id = ? AND is_deleted = 0');
    $st->execute([$postId]);
    if (!$st->fetch()) {
      return ['ok' => false, 'error' => 'post not found'];
    }
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'db: ' . $e->getMessage()];
  }

  try {
    $st = db()->prepare('SELECT 1 FROM forum_post_likes WHERE post_id = ? AND user_id = ?');
    $st->execute([$postId, $userId]);
    if ($st->fetchColumn()) {
      db()->prepare('DELETE FROM forum_post_likes WHERE post_id = ? AND user_id = ?')->execute([$postId, $userId]);
      $liked = false;
    } else {
      db()->prepare('INSERT INTO forum_post_likes (post_id, user_id) VALUES (?, ?)')->execute([$postId, $userId]);
      $liked = true;
    }
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'like: ' . $e->getMessage()];
  }

  $count = forum_post_like_count($postId);
  // синхронизируем кэш-колонку (если есть)
  try {
    db()->prepare('UPDATE forum_posts SET like_count = ? WHERE id = ?')->execute([$count, $postId]);
  } catch (Throwable $e) {}

  return ['ok' => true, 'liked' => $liked, 'count' => $count];
}

function forum_thread_watch_toggle(int $threadId, int $userId): array {
  forum_engine_ensure();
  if ($threadId <= 0 || $userId <= 0) return ['ok' => false, 'error' => 'bad'];
  try {
    $st = db()->prepare('SELECT 1 FROM forum_thread_watch WHERE thread_id = ? AND user_id = ?');
    $st->execute([$threadId, $userId]);
    if ($st->fetchColumn()) {
      db()->prepare('DELETE FROM forum_thread_watch WHERE thread_id = ? AND user_id = ?')->execute([$threadId, $userId]);
      return ['ok' => true, 'watching' => false];
    }
    db()->prepare('INSERT INTO forum_thread_watch (thread_id, user_id) VALUES (?, ?)')->execute([$threadId, $userId]);
    return ['ok' => true, 'watching' => true];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function forum_thread_is_watching(int $threadId, int $userId): bool {
  forum_engine_ensure();
  try {
    $st = db()->prepare('SELECT 1 FROM forum_thread_watch WHERE thread_id = ? AND user_id = ?');
    $st->execute([$threadId, $userId]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function forum_mark_read(int $threadId, int $userId, int $lastPostId): void {
  if ($userId <= 0 || $threadId <= 0) return;
  forum_engine_ensure();
  try {
    db()->prepare(
      'INSERT INTO forum_thread_reads (thread_id, user_id, last_post_id, last_read_at) VALUES (?,?,?,NOW())
       ON DUPLICATE KEY UPDATE last_post_id = GREATEST(last_post_id, VALUES(last_post_id)), last_read_at = NOW()'
    )->execute([$threadId, $userId, $lastPostId]);
  } catch (Throwable $e) {}
}

function forum_report_post(int $postId, int $reporterId, string $reason): array {
  forum_engine_ensure();
  $reason = mb_substr(trim($reason), 0, 255);
  if ($postId <= 0 || $reporterId <= 0) return ['ok' => false, 'error' => 'bad'];
  try {
    $st = db()->prepare("SELECT id FROM forum_post_reports WHERE post_id = ? AND reporter_id = ? AND status = 'open'");
    $st->execute([$postId, $reporterId]);
    if ($st->fetch()) return ['ok' => false, 'error' => 'already'];
    db()->prepare('INSERT INTO forum_post_reports (post_id, reporter_id, reason) VALUES (?,?,?)')
      ->execute([$postId, $reporterId, $reason !== '' ? $reason : 'spam']);
    return ['ok' => true];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function forum_edit_post(int $postId, int $userId, string $message, bool $asMod): array {
  forum_engine_ensure();
  $message = trim($message);
  if ($message === '') return ['ok' => false, 'error' => 'empty'];
  try {
    $st = db()->prepare('SELECT * FROM forum_posts WHERE id = ? AND is_deleted = 0');
    $st->execute([$postId]);
    $post = $st->fetch();
    if (!$post) return ['ok' => false, 'error' => 'not found'];
    if (!$asMod && (int)$post['user_id'] !== $userId) return ['ok' => false, 'error' => 'forbidden'];
    db()->prepare('UPDATE forum_posts SET message = ?, updated_at = NOW(), edited_by = ? WHERE id = ?')
      ->execute([mb_substr($message, 0, 2000000), $userId, $postId]);
    return ['ok' => true, 'thread_id' => (int)$post['thread_id']];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function forum_bump_reply_stats(int $threadId, int $userId): void {
  try {
    db()->prepare(
      'UPDATE forum_threads SET last_post_at = NOW(), last_post_user_id = ?, reply_count = IFNULL(reply_count,0) + 1 WHERE id = ?'
    )->execute([$userId, $threadId]);
  } catch (Throwable $e) {
    try {
      db()->prepare('UPDATE forum_threads SET last_post_at = NOW() WHERE id = ?')->execute([$threadId]);
    } catch (Throwable $e2) {}
  }
}

function forum_user_liked_posts(int $userId, array $postIds): array {
  if ($userId <= 0 || !$postIds) return [];
  forum_engine_ensure();
  $ids = array_values(array_unique(array_filter(array_map('intval', $postIds))));
  if (!$ids) return [];
  $in = implode(',', $ids);
  try {
    $rows = db()->query(
      'SELECT post_id FROM forum_post_likes WHERE user_id = ' . (int)$userId . ' AND post_id IN (' . $in . ')'
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows ?: []);
  } catch (Throwable $e) {
    return [];
  }
}

function forum_search(string $q, int $limit = 30): array {
  $q = trim($q);
  if (mb_strlen($q) < 2) return [];
  forum_engine_ensure();
  $limit = max(1, min(50, $limit));
  $like = '%' . $q . '%';
  $out = [];
  try {
    $st = db()->prepare(
      "SELECT t.id, t.title, t.last_post_at, u.username, fc.title AS cat
       FROM forum_threads t
       JOIN users u ON u.id = t.user_id
       JOIN forum_categories fc ON fc.id = t.category_id
       WHERE t.is_deleted = 0 AND t.title LIKE ?
       ORDER BY t.last_post_at DESC LIMIT {$limit}"
    );
    $st->execute([$like]);
    foreach ($st->fetchAll() as $r) {
      $out[] = [
        'type' => 'thread',
        'title' => $r['title'],
        'meta' => $r['cat'] . ' · @' . $r['username'],
        'url' => '/forum_thread.php?id=' . (int)$r['id'],
      ];
    }
  } catch (Throwable $e) {}
  try {
    $st = db()->prepare(
      "SELECT p.id, p.thread_id, p.message, t.title, u.username
       FROM forum_posts p
       JOIN forum_threads t ON t.id = p.thread_id
       JOIN users u ON u.id = p.user_id
       WHERE p.is_deleted = 0 AND t.is_deleted = 0 AND p.message LIKE ?
       ORDER BY p.created_at DESC LIMIT {$limit}"
    );
    $st->execute([$like]);
    foreach ($st->fetchAll() as $r) {
      $out[] = [
        'type' => 'post',
        'title' => $r['title'],
        'meta' => '@' . $r['username'] . ' · ' . mb_substr(strip_tags($r['message']), 0, 100),
        'url' => '/forum_thread.php?id=' . (int)$r['thread_id'] . '#post-' . (int)$r['id'],
      ];
    }
  } catch (Throwable $e) {}
  return $out;
}
