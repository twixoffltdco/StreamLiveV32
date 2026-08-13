<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rss_vk.php';
$base = rss_vk_base();
$site = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$limit = min(300, max(20, (int)($_GET['limit'] ?? 200)));
$items = [];
try {
  $st = db()->query("SELECT * FROM channels WHERE status='approved' ORDER BY COALESCE(created_at,id) DESC LIMIT $limit");
  foreach ($st->fetchAll() ?: [] as $c) {
    $link = $base . '/channel.php?slug=' . rawurlencode((string)$c['slug']);
    $items[] = [
      'title' => (string)$c['title'],
      'link' => $link,
      'guid' => $link,
      'description' => mb_substr(strip_tags((string)($c['description'] ?? $c['title'])), 0, 400),
      'pub_date' => $c['created_at'] ?? null,
      'image' => (string)($c['logo_url'] ?? ''),
    ];
  }
} catch (Throwable $e) {}
rss_vk_render([
  'title' => $site . ' — Каналы',
  'link' => $base . '/catalog.php',
  'description' => 'Каналы ' . $site,
], $items);
