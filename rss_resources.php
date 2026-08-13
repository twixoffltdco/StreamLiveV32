<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rss_vk.php';
$base = rss_vk_base();
$site = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$limit = min(300, max(20, (int)($_GET['limit'] ?? 200)));
$items = [];
try {
  $st = db()->query("SELECT * FROM resources WHERE status='published' ORDER BY id DESC LIMIT $limit");
  foreach ($st->fetchAll() ?: [] as $r) {
    $link = $base . '/resource.php?slug=' . rawurlencode((string)$r['slug']);
    $items[] = [
      'title' => (string)$r['title'],
      'link' => $link,
      'guid' => $link,
      'description' => mb_substr(strip_tags((string)($r['summary'] ?? $r['readme'] ?? $r['title'])), 0, 400),
      'pub_date' => $r['created_at'] ?? null,
      'image' => (string)($r['screenshot'] ?? ''),
    ];
  }
} catch (Throwable $e) {}
rss_vk_render([
  'title' => $site . ' — Ресурсы',
  'link' => $base . '/resources.php',
  'description' => 'Ресурсы ' . $site,
], $items);
