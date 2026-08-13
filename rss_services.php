<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rss_vk.php';
$base = rss_vk_base();
$site = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$limit = min(200, max(20, (int)($_GET['limit'] ?? 100)));
$items = [];
try {
  $st = db()->query("SELECT * FROM deployed_services WHERE status='live' ORDER BY id DESC LIMIT $limit");
  foreach ($st->fetchAll() ?: [] as $s) {
    $slug = (string)($s['slug'] ?? $s['id']);
    $link = $base . '/s/' . rawurlencode($slug);
    $items[] = [
      'title' => (string)($s['title'] ?? $s['name'] ?? 'Сервис'),
      'link' => $link,
      'guid' => $link,
      'description' => mb_substr(strip_tags((string)($s['description'] ?? '')), 0, 300),
      'pub_date' => $s['created_at'] ?? null,
      'image' => '',
    ];
  }
} catch (Throwable $e) {}
rss_vk_render([
  'title' => $site . ' — Сервисы',
  'link' => $base . '/',
  'description' => 'Сервисы ' . $site,
], $items);
