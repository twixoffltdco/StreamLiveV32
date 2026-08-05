<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rss_vk.php';
$base = rss_vk_base();
$site = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$limit = min(300, max(20, (int)($_GET['limit'] ?? 200)));
$items = [];
try {
  $st = db()->query("SELECT id, title, created_at FROM forum_threads ORDER BY id DESC LIMIT $limit");
  foreach ($st->fetchAll() ?: [] as $t) {
    $link = $base . '/forum_thread.php?id=' . (int)$t['id'];
    $items[] = [
      'title' => (string)$t['title'],
      'link' => $link,
      'guid' => $link,
      'description' => (string)$t['title'],
      'pub_date' => $t['created_at'] ?? null,
      'image' => '',
    ];
  }
} catch (Throwable $e) {}
rss_vk_render([
  'title' => $site . ' — Форум',
  'link' => $base . '/forum.php',
  'description' => 'Темы форума ' . $site,
], $items);
