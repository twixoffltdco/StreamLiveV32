<?php
/**
 * Модерация: темы / посты / видео. Без фаталов, если колонок ещё нет.
 */
function cmod_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    $pdo = db();
  } catch (Throwable $e) {
    return;
  }
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS content_moderation_queue (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        target_type VARCHAR(16) NOT NULL,
        target_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NULL,
        snippet TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        approve_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        reject_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        UNIQUE KEY uq_target (target_type, target_id),
        KEY idx_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS content_moderation_votes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        queue_id INT UNSIGNED NOT NULL,
        moderator_id INT UNSIGNED NOT NULL,
        vote VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mod_item (queue_id, moderator_id),
        KEY idx_mod (moderator_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS moderator_action_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        action VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_time (user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  foreach ([
    "ALTER TABLE forum_threads ADD COLUMN mod_status VARCHAR(20) NOT NULL DEFAULT 'approved'",
    "ALTER TABLE forum_posts ADD COLUMN mod_status VARCHAR(20) NOT NULL DEFAULT 'approved'",
    "ALTER TABLE videos ADD COLUMN mod_status VARCHAR(20) NOT NULL DEFAULT 'approved'",
  ] as $q) {
    try { $pdo->exec($q); } catch (Throwable $e) {}
  }
}

function cmod_is_admin(?array $u): bool {
  return is_array($u) && (string)($u['role'] ?? '') === 'admin';
}

function cmod_is_moderator(?array $u): bool {
  if (!is_array($u)) return false;
  if (cmod_is_admin($u)) return true;
  $r = (string)($u['role'] ?? '');
  if (in_array($r, ['moderator', 'forum_moderator'], true)) return true;
  if (!empty($u['is_moderator'])) return true;
  try {
    if (function_exists('is_forum_moderator') && is_forum_moderator($u)) return true;
  } catch (Throwable $e) {}
  return false;
}

function cmod_needs_moderation(?array $u): bool {
  // Контент ВСЕХ (юзеры, модеры, админы) идёт в очередь —
  // иначе нанятые модераторы могут постить что угодно без проверки.
  return true;
}

function cmod_can_act(?array $u): array {
  if (!is_array($u)) return ['ok' => false, 'error' => 'Войдите'];
  if (cmod_is_admin($u)) return ['ok' => true, 'error' => ''];
  if (!cmod_is_moderator($u)) return ['ok' => false, 'error' => 'Нет прав'];
  cmod_ensure_schema();
  try {
    $st = db()->prepare('SELECT created_at FROM moderator_action_log WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    if ($row) {
      $t = strtotime((string)$row['created_at']);
      $wait = 100 * 3600;
      if ($t && (time() - $t) < $wait) {
        $h = (int)ceil(($wait - (time() - $t)) / 3600);
        return ['ok' => false, 'error' => "Лимит модератора: ~{$h} ч"];
      }
    }
  } catch (Throwable $e) {}
  return ['ok' => true, 'error' => ''];
}

function cmod_log_action(int $uid, string $action): void {
  try {
    db()->prepare('INSERT INTO moderator_action_log (user_id, action) VALUES (?,?)')->execute([$uid, $action]);
  } catch (Throwable $e) {}
}

function cmod_enqueue(string $type, int $id, int $userId, string $title = '', string $snippet = ''): void {
  try {
    cmod_ensure_schema();
    $type = in_array($type, ['thread', 'post', 'video'], true) ? $type : 'thread';
    db()->prepare(
      "INSERT INTO content_moderation_queue (target_type, target_id, user_id, title, snippet, status)
       VALUES (?,?,?,?,?,'pending')
       ON DUPLICATE KEY UPDATE status='pending', title=VALUES(title), snippet=VALUES(snippet),
         decided_at=NULL, approve_count=0, reject_count=0"
    )->execute([$type, $id, $userId, mb_substr($title, 0, 255), mb_substr($snippet, 0, 500)]);
    $table = $type === 'video' ? 'videos' : ($type === 'post' ? 'forum_posts' : 'forum_threads');
    try {
      db()->prepare("UPDATE `$table` SET mod_status = 'pending' WHERE id = ?")->execute([$id]);
    } catch (Throwable $e) {}
  } catch (Throwable $e) {}
}

function cmod_set_approved(string $type, int $id): void {
  try {
    cmod_ensure_schema();
    $table = $type === 'video' ? 'videos' : ($type === 'post' ? 'forum_posts' : 'forum_threads');
    db()->prepare("UPDATE `$table` SET mod_status = 'approved' WHERE id = ?")->execute([$id]);
  } catch (Throwable $e) {}
}

function cmod_vote(?array $u, int $queueId, string $vote): array {
  try {
    cmod_ensure_schema();
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'schema'];
  }
  if (!is_array($u)) return ['ok' => false, 'error' => 'Войдите'];
  $can = cmod_can_act($u);
  if (!$can['ok']) return $can;
  $vote = $vote === 'reject' ? 'reject' : 'approve';
  $uid = (int)$u['id'];
  $isAdmin = cmod_is_admin($u);
  try {
    $st = db()->prepare('SELECT * FROM content_moderation_queue WHERE id = ?');
    $st->execute([$queueId]);
    $item = $st->fetch();
    if (!$item || $item['status'] !== 'pending') {
      return ['ok' => false, 'error' => 'Уже решено'];
    }
    db()->prepare(
      'INSERT INTO content_moderation_votes (queue_id, moderator_id, vote) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE vote = VALUES(vote)'
    )->execute([$queueId, $uid, $vote]);
    // Нельзя модерировать СВОЙ контент — нужен другой модератор/админ
    if ((int)($item['user_id'] ?? 0) === $uid) {
      return ['ok' => false, 'error' => 'Нельзя модерировать свой контент — пусть подтвердит другой модератор'];
    }

    cmod_log_action($uid, $vote . ':' . $item['target_type']);

    $votes = db()->prepare('SELECT vote, COUNT(DISTINCT moderator_id) c FROM content_moderation_votes WHERE queue_id = ? GROUP BY vote');
    $votes->execute([$queueId]);
    $ap = $rj = 0;
    foreach ($votes->fetchAll() as $v) {
      if ($v['vote'] === 'approve') $ap = (int)$v['c'];
      if ($v['vote'] === 'reject') $rj = (int)$v['c'];
    }
    db()->prepare('UPDATE content_moderation_queue SET approve_count=?, reject_count=? WHERE id=?')
      ->execute([$ap, $rj, $queueId]);

    // Всегда нужно подтверждение минимум двух разных модераторов/админов
    // (админ = один голос, не «сразу финал» — защита от одного плохого модератора)
    $final = null;
    if ($rj >= 2) $final = 'rejected';
    elseif ($ap >= 2) $final = 'approved';

    if ($final) {
      db()->prepare("UPDATE content_moderation_queue SET status=?, decided_at=NOW() WHERE id=?")
        ->execute([$final, $queueId]);
      cmod_apply_target($item, $final);
    }
    return ['ok' => true, 'error' => '', 'final' => $final, 'approves' => $ap, 'rejects' => $rj];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function cmod_apply_target(array $item, string $final): void {
  $type = $item['target_type'] ?? '';
  $id = (int)($item['target_id'] ?? 0);
  if ($id <= 0) return;
  $table = $type === 'video' ? 'videos' : ($type === 'post' ? 'forum_posts' : 'forum_threads');
  try {
    db()->prepare("UPDATE `$table` SET mod_status = ? WHERE id = ?")->execute([$final, $id]);
  } catch (Throwable $e) {}
  if ($type === 'video') {
    try {
      if ($final === 'approved') {
        db()->prepare("UPDATE videos SET status='published' WHERE id=?")->execute([$id]);
      } elseif ($final === 'rejected') {
        db()->prepare("UPDATE videos SET status='failed' WHERE id=?")->execute([$id]);
      }
    } catch (Throwable $e) {}
  }
}

function cmod_banner_html(string $kind = 'thread'): string {
  $map = [
    'thread' => 'Ваша тема на модерации. После проверки она появится на форуме.',
    'post' => 'Сообщение на модерации. Пока его видите только вы.',
    'video' => 'Видео на модерации. После одобрения появится в каталоге.',
  ];
  $msg = $map[$kind] ?? $map['thread'];
  return '<div class="cmod-banner" style="margin:12px 0;padding:14px 16px;border-radius:12px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35)">'
    . '<b>На модерации</b><br><span style="font-size:14px">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</span></div>';
}
