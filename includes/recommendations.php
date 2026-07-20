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
