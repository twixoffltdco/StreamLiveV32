<?php
/** Плагины форума: теги + опросы (до 10 вариантов) */

function forum_plugins_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_tags (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(64) NOT NULL,
      slug VARCHAR(80) NOT NULL,
      uses_count INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_thread_tags (
      thread_id INT NOT NULL, tag_id INT NOT NULL,
      PRIMARY KEY (thread_id, tag_id), KEY idx_tag (tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_polls (
      id INT AUTO_INCREMENT PRIMARY KEY,
      thread_id INT NOT NULL,
      question VARCHAR(300) NOT NULL,
      options_json TEXT NOT NULL,
      is_multi TINYINT(1) NOT NULL DEFAULT 0,
      closes_at DATETIME NULL,
      created_by INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_thread (thread_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS forum_poll_votes (
      poll_id INT NOT NULL, user_id INT NOT NULL, option_idx TINYINT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (poll_id, user_id, option_idx)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}
}

function forum_slugify_tag(string $name): string {
  $s = mb_strtolower(trim($name));
  $s = preg_replace('/\s+/u', '-', $s);
  $s = preg_replace('/[^\p{L}\p{N}\-_]/u', '', $s);
  return mb_substr($s !== '' ? $s : 'tag', 0, 80);
}

function forum_attach_tags(int $threadId, array $names): void {
  forum_plugins_ensure();
  $pdo = db();
  foreach ($names as $name) {
    $name = trim(mb_substr($name, 0, 64));
    if ($name === '') continue;
    $slug = forum_slugify_tag($name);
    try {
      $pdo->prepare('INSERT IGNORE INTO forum_tags (name, slug) VALUES (?, ?)')->execute([$name, $slug]);
      $st = $pdo->prepare('SELECT id FROM forum_tags WHERE slug = ?');
      $st->execute([$slug]);
      $tid = (int)$st->fetchColumn();
      if ($tid <= 0) continue;
      $pdo->prepare('INSERT IGNORE INTO forum_thread_tags (thread_id, tag_id) VALUES (?, ?)')->execute([$threadId, $tid]);
      $pdo->prepare('UPDATE forum_tags SET uses_count = uses_count + 1 WHERE id = ?')->execute([$tid]);
    } catch (Throwable $e) {}
  }
}

function forum_thread_tags(int $threadId): array {
  forum_plugins_ensure();
  try {
    $st = db()->prepare('SELECT t.id, t.name, t.slug FROM forum_tags t
      JOIN forum_thread_tags tt ON tt.tag_id = t.id WHERE tt.thread_id = ? ORDER BY t.name');
    $st->execute([$threadId]);
    return $st->fetchAll() ?: [];
  } catch (Throwable $e) { return []; }
}

function forum_create_poll(int $threadId, int $userId, string $question, array $options, bool $multi = false, ?string $closesAt = null): void {
  forum_plugins_ensure();
  $question = trim(mb_substr($question, 0, 300));
  $opts = [];
  foreach ($options as $o) {
    $o = trim(mb_substr((string)$o, 0, 200));
    if ($o !== '') $opts[] = $o;
  }
  if ($question === '' || count($opts) < 2) return;
  $opts = array_slice($opts, 0, 10);
  db()->prepare('INSERT INTO forum_polls (thread_id, question, options_json, is_multi, closes_at, created_by)
    VALUES (?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE question=VALUES(question), options_json=VALUES(options_json), is_multi=VALUES(is_multi), closes_at=VALUES(closes_at)')
    ->execute([$threadId, $question, json_encode($opts, JSON_UNESCAPED_UNICODE), $multi ? 1 : 0, $closesAt, $userId]);
}

function forum_poll_for_thread(int $threadId): ?array {
  forum_plugins_ensure();
  try {
    $st = db()->prepare('SELECT * FROM forum_polls WHERE thread_id = ?');
    $st->execute([$threadId]);
    $p = $st->fetch();
    if (!$p) return null;
    $p['options'] = json_decode($p['options_json'] ?? '[]', true) ?: [];
    return $p;
  } catch (Throwable $e) { return null; }
}

function forum_poll_results(int $pollId): array {
  try {
    $st = db()->prepare('SELECT option_idx, COUNT(*) AS c FROM forum_poll_votes WHERE poll_id = ? GROUP BY option_idx');
    $st->execute([$pollId]);
    $map = []; $total = 0;
    foreach ($st->fetchAll() as $r) {
      $map[(int)$r['option_idx']] = (int)$r['c'];
      $total += (int)$r['c'];
    }
    return ['counts' => $map, 'total' => $total];
  } catch (Throwable $e) { return ['counts' => [], 'total' => 0]; }
}

function forum_user_voted(int $pollId, int $userId): array {
  try {
    $st = db()->prepare('SELECT option_idx FROM forum_poll_votes WHERE poll_id = ? AND user_id = ?');
    $st->execute([$pollId, $userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  } catch (Throwable $e) { return []; }
}

function forum_poll_vote(int $pollId, int $userId, array $optionIdxs, bool $multi): bool {
  forum_plugins_ensure();
  $st = db()->prepare('SELECT * FROM forum_polls WHERE id = ?');
  $st->execute([$pollId]);
  $p = $st->fetch();
  if (!$p) return false;
  if (!empty($p['closes_at']) && strtotime($p['closes_at']) < time()) return false;
  $opts = json_decode($p['options_json'] ?? '[]', true) ?: [];
  $max = count($opts) - 1;
  $idxs = [];
  foreach ($optionIdxs as $i) {
    $i = (int)$i;
    if ($i >= 0 && $i <= $max) $idxs[] = $i;
  }
  if (!$idxs) return false;
  if (!$multi) $idxs = [$idxs[0]];
  $pdo = db();
  $pdo->prepare('DELETE FROM forum_poll_votes WHERE poll_id = ? AND user_id = ?')->execute([$pollId, $userId]);
  $ins = $pdo->prepare('INSERT INTO forum_poll_votes (poll_id, user_id, option_idx) VALUES (?,?,?)');
  foreach ($idxs as $i) $ins->execute([$pollId, $userId, $i]);
  return true;
}


function forum_poll_voters(int $pollId): array {
  try {
    $st = db()->prepare('SELECT v.option_idx, u.id, u.username FROM forum_poll_votes v JOIN users u ON u.id = v.user_id WHERE v.poll_id = ? ORDER BY v.option_idx, u.username');
    $st->execute([$pollId]);
    return $st->fetchAll() ?: [];
  } catch (Throwable $e) { return []; }
}

function forum_render_poll_html(array $poll, ?int $userId, bool $showVoters = false): string {
  $opts = $poll['options'] ?? [];
  $res = forum_poll_results((int)$poll['id']);
  $voted = $userId ? forum_user_voted((int)$poll['id'], $userId) : [];
  $closed = !empty($poll['closes_at']) && strtotime($poll['closes_at']) < time();
  $html = '<div class="forum-poll" style="margin:16px 0;padding:16px;border-radius:12px;border:1px solid var(--border,rgba(255,255,255,.1));background:rgba(255,255,255,.03)">';
  $html .= '<div style="font-weight:600;margin-bottom:10px">📊 ' . htmlspecialchars($poll['question'], ENT_QUOTES, 'UTF-8') . '</div>';
  if ($userId && !$closed && !$voted) {
    $html .= '<form method="POST" action="/forum_poll_vote.php" style="display:grid;gap:8px">';
    $html .= '<input type="hidden" name="poll_id" value="' . (int)$poll['id'] . '">';
    $html .= '<input type="hidden" name="thread_id" value="' . (int)$poll['thread_id'] . '">';
    if (function_exists('csrf_field')) $html .= csrf_field();
    foreach ($opts as $i => $o) {
      $type = !empty($poll['is_multi']) ? 'checkbox' : 'radio';
      $name = !empty($poll['is_multi']) ? 'opt[]' : 'opt';
      $html .= '<label style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="' . $type . '" name="' . $name . '" value="' . $i . '"> ' . htmlspecialchars($o, ENT_QUOTES, 'UTF-8') . '</label>';
    }
    $html .= '<button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px;width:fit-content">Голосовать</button></form>';
  } else {
    foreach ($opts as $i => $o) {
      $c = (int)($res['counts'][$i] ?? 0);
      $pct = $res['total'] > 0 ? round(100 * $c / $res['total']) : 0;
      $mark = in_array($i, $voted, true) ? ' ✓' : '';
      $html .= '<div style="margin:8px 0"><div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px"><span>' . htmlspecialchars($o, ENT_QUOTES, 'UTF-8') . $mark . '</span><span>' . $c . ' · ' . $pct . '%</span></div>';
      $html .= '<div style="height:8px;border-radius:4px;background:rgba(255,255,255,.08);overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(90deg,#a78bfa,#22d3ee)"></div></div></div>';
    }
    $html .= '<div style="font-size:12px;opacity:.65;margin-top:8px">Всего голосов: ' . (int)$res['total'] . ($closed ? ' · опрос закрыт' : '') . '</div>';
  }
  if ($showVoters) {
    $voters = forum_poll_voters((int)$poll['id']);
    if ($voters) {
      $html .= '<details style="margin-top:10px;font-size:13px"><summary>Кто голосовал (' . count($voters) . ')</summary><ul style="margin:8px 0 0;padding-left:18px">';
      $opts = $poll['options'] ?? [];
      foreach ($voters as $vr) {
        $on = $opts[(int)$vr['option_idx']] ?? ('#'.$vr['option_idx']);
        $html .= '<li>@' . htmlspecialchars($vr['username'], ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars((string)$on, ENT_QUOTES, 'UTF-8') . '</li>';
      }
      $html .= '</ul></details>';
    }
  }
  $html .= '</div>';
  return $html;
}
