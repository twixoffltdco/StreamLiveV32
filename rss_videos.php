<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rss_vk.php';
$base = rss_vk_base();
$site = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$limit = min(300, max(20, (int)($_GET['limit'] ?? 200)));
$items = [];
try {
  $st = db()->query("SELECT * FROM videos WHERE status='published' ORDER BY COALESCE(created_at,id) DESC LIMIT $limit");
  foreach ($st->fetchAll() ?: [] as $v) {
    $link = $base . '/video.php?slug=' . rawurlencode((string)$v['slug']);
    $items[] = [
      'title' => (string)$v['title'],
      'link' => $link,
      'guid' => $link,
      'description' => mb_substr(strip_tags((string)($v['description'] ?? $v['title'])), 0, 400),
      'pub_date' => $v['created_at'] ?? null,
      'image' => (string)($v['thumbnail_url'] ?? ''),
    ];
  }
} catch (Throwable $e) {}
rss_vk_render([
  'title' => $site . ' — Видео',
  'link' => $base . '/videos.php',
  'description' => 'Все видео ' . $site,
], $items);
