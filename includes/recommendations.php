<?php
// Персональные рекомендации без сложного ML (нереалистично на бесплатном PHP-хостинге) —
// честный, работающий вариант: смотрим теги последних просмотренных ЭТИМ БРАУЗЕРОМ видео
// (через cookie, у каждого посетителя свои — даже без входа в аккаунт) и подбираем
// видео с похожими тегами. Плюс всегда логируем в video_views_log для будущей аналитики.

const RECS_COOKIE_NAME = 'vh'; // video history
const RECS_HISTORY_LIMIT = 20;

// Вызывать на странице просмотра видео — записывает текущее видео в историю браузера
function record_video_view(int $videoId, ?int $userId): void {
  $history = [];
  if (!empty($_COOKIE[RECS_COOKIE_NAME])) {
    $history = array_filter(array_map('intval', explode(',', $_COOKIE[RECS_COOKIE_NAME])));
  }
  $history = array_diff($history, [$videoId]); // не дублируем
  array_unshift($history, $videoId);
  $history = array_slice($history, 0, RECS_HISTORY_LIMIT);
  setcookie(RECS_COOKIE_NAME, implode(',', $history), time() + 60 * 60 * 24 * 90, '/');

  try {
    $visitorId = null;
    if (!$userId) {
      $visitorId = $_COOKIE['vid'] ?? bin2hex(random_bytes(16));
      setcookie('vid', $visitorId, time() + 60 * 60 * 24 * 365, '/');
    }
    db()->prepare('INSERT INTO video_views_log (video_id, user_id, visitor_id) VALUES (?, ?, ?)')
      ->execute([$videoId, $userId, $visitorId]);
  } catch (\Throwable $e) { /* лог просмотров необязателен, не роняем страницу */ }
}

// Возвращает до $limit рекомендованных видео на основе тегов из истории браузера этого посетителя
function get_recommended_videos(int $excludeVideoId, int $limit = 12): array {
  $history = [];
  if (!empty($_COOKIE[RECS_COOKIE_NAME])) {
    $history = array_filter(array_map('intval', explode(',', $_COOKIE[RECS_COOKIE_NAME])));
  }

  if (!$history) {
    // Нет истории (первый визит) — просто последние опубликованные, это разумный дефолт
    $stmt = db()->prepare("SELECT v.*, c.title AS channel_title FROM videos v JOIN channels c ON c.id=v.channel_id WHERE v.status='published' AND v.id != ? ORDER BY v.created_at DESC LIMIT ?");
    $stmt->bindValue(1, $excludeVideoId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
  }

  // Собираем теги из просмотренных видео
  $placeholders = implode(',', array_fill(0, count($history), '?'));
  $stmt = db()->prepare("SELECT tags FROM videos WHERE id IN ({$placeholders}) AND tags != ''");
  $stmt->execute(array_values($history));
  $tagCounts = [];
  foreach ($stmt->fetchAll() as $row) {
    foreach (explode(',', $row['tags']) as $tag) {
      $tag = trim(mb_strtolower($tag));
      if ($tag === '') continue;
      $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
    }
  }

  if (!$tagCounts) {
    // В истории видео вообще без тегов — фолбэк на последние
    $stmt = db()->prepare("SELECT v.*, c.title AS channel_title FROM videos v JOIN channels c ON c.id=v.channel_id WHERE v.status='published' AND v.id != ? ORDER BY v.created_at DESC LIMIT ?");
    $stmt->bindValue(1, $excludeVideoId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
  }

  // Берём топ-8 самых частых тегов в истории этого браузера и ищем видео с совпадениями,
  // считаем простой score = сколько тегов из истории встречается у кандидата
  arsort($tagCounts);
  $topTags = array_slice(array_keys($tagCounts), 0, 8);

  $excludeIds = array_unique(array_merge($history, [$excludeVideoId]));
  $excludePlaceholders = implode(',', array_fill(0, count($excludeIds), '?'));

  $stmt = db()->prepare(
    "SELECT v.*, c.title AS channel_title FROM videos v JOIN channels c ON c.id = v.channel_id
     WHERE v.status = 'published' AND v.id NOT IN ({$excludePlaceholders}) AND v.tags != ''
     ORDER BY v.created_at DESC LIMIT 300" // берём пул посвежее и ранжируем в PHP — проще и надёжнее, чем городить SQL под релевантность на MySQL без полнотекстового движка под теги
  );
  $stmt->execute(array_values($excludeIds));
  $candidates = $stmt->fetchAll();

  foreach ($candidates as &$c) {
    $candidateTags = array_map(fn($t) => trim(mb_strtolower($t)), explode(',', $c['tags']));
    $c['_score'] = count(array_intersect($candidateTags, $topTags));
  }
  unset($c);

  usort($candidates, fn($a, $b) => $b['_score'] <=> $a['_score']);
  $relevant = array_filter($candidates, fn($c) => $c['_score'] > 0);

  $result = array_slice($relevant, 0, $limit);
  if (count($result) < $limit) {
    // Не хватило по тегам — дополняем последними видео, чтобы лента не была пустой
    $stmt = db()->prepare("SELECT v.*, c.title AS channel_title FROM videos v JOIN channels c ON c.id=v.channel_id WHERE v.status='published' AND v.id NOT IN ({$excludePlaceholders}) ORDER BY v.created_at DESC LIMIT ?");
    $params = array_values($excludeIds);
    $params[] = $limit - count($result);
    foreach ($params as $i => $p) { $stmt->bindValue($i + 1, $p, PDO::PARAM_INT); }
    $stmt->execute();
    $existingIds = array_column($result, 'id');
    foreach ($stmt->fetchAll() as $extra) {
      if (!in_array($extra['id'], $existingIds, true)) $result[] = $extra;
    }
  }

  return array_slice($result, 0, $limit);
}


/**
 * Блок «Для вас» — рекомендации видео (и опционально заглушка forum).
 * Каналы рисует platforma/recommendations_block.php отдельно.
 */
function render_recommendations_section(string $kind = 'videos', int $limit = 8): void {
  $kind = $kind === 'forum' ? 'forum' : 'videos';
  $limit = max(3, min(24, $limit));

  if ($kind === 'forum') {
    // Темы: последние approved (мягко, без фатала)
    try {
      $rows = db()->query(
        "SELECT t.id, t.title, t.created_at, u.username
         FROM forum_threads t
         JOIN users u ON u.id = t.user_id
         WHERE t.is_deleted = 0
           AND COALESCE(t.mod_status, 'approved') = 'approved'
         ORDER BY t.created_at DESC
         LIMIT " . (int)$limit
      )->fetchAll() ?: [];
    } catch (Throwable $e) {
      try {
        $rows = db()->query(
          "SELECT t.id, t.title, t.created_at, u.username
           FROM forum_threads t JOIN users u ON u.id = t.user_id
           WHERE t.is_deleted = 0 ORDER BY t.created_at DESC LIMIT " . (int)$limit
        )->fetchAll() ?: [];
      } catch (Throwable $e2) {
        $rows = [];
      }
    }
    if (!$rows) return;
    echo '<section class="recs-block" style="margin:16px 0 20px">';
    echo '<h2 style="font-size:1.15rem;margin:0 0 12px">Для вас · темы форума</h2>';
    echo '<div style="display:grid;gap:8px">';
    foreach ($rows as $r) {
      $id = (int)$r['id'];
      $title = htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8');
      $user = htmlspecialchars((string)$r['username'], ENT_QUOTES, 'UTF-8');
      echo '<a href="/forum_thread.php?id=' . $id . '" style="display:block;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.08);text-decoration:none;color:inherit">';
      echo '<div style="font-weight:600">' . $title . '</div>';
      echo '<div style="font-size:12px;opacity:.65">' . $user . '</div>';
      echo '</a>';
    }
    echo '</div></section>';
    return;
  }

  // videos
  try {
    $list = function_exists('get_recommended_videos')
      ? get_recommended_videos(0, $limit)
      : [];
  } catch (Throwable $e) {
    $list = [];
  }
  // только опубликованные / approved
  $list = array_values(array_filter($list ?: [], static function ($v) {
    $st = strtolower((string)($v['status'] ?? 'published'));
    $ms = strtolower((string)($v['mod_status'] ?? 'approved'));
    if ($st !== '' && !in_array($st, ['published', 'scheduled'], true)) return false;
    if ($ms !== '' && $ms !== 'approved') return false;
    return true;
  }));
  if (!$list) {
    try {
      $list = db()->query(
        "SELECT v.*, c.title AS channel_title FROM videos v
         JOIN channels c ON c.id = v.channel_id
         WHERE v.status = 'published'
         ORDER BY v.id DESC LIMIT " . (int)$limit
      )->fetchAll() ?: [];
    } catch (Throwable $e) {
      $list = [];
    }
  }
  if (!$list) return;

  echo '<section class="recs-block recs-videos" style="margin:16px 0 22px">';
  echo '<h2 style="font-size:1.15rem;margin:0 0 12px">Для вас · видео</h2>';
  echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">';
  foreach ($list as $v) {
    $slug = htmlspecialchars((string)($v['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars(mb_substr((string)($v['title'] ?? ''), 0, 80), ENT_QUOTES, 'UTF-8');
    $ch = htmlspecialchars((string)($v['channel_title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $thumb = trim((string)($v['thumbnail_url'] ?? ''));
    if ($thumb === '') $thumb = '/assets/img/video-placeholder.png';
    $thumb = htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8');
    echo '<a href="/video.php?slug=' . $slug . '" style="text-decoration:none;color:inherit">';
    echo '<div style="aspect-ratio:16/9;border-radius:10px;background:#111 url(\'' . $thumb . '\') center/cover;margin-bottom:6px"></div>';
    echo '<div style="font-size:13px;font-weight:600;line-height:1.3">' . $title . '</div>';
    if ($ch !== '') echo '<div style="font-size:11px;opacity:.6;margin-top:2px">' . $ch . '</div>';
    echo '</a>';
  }
  echo '</div></section>';
}
