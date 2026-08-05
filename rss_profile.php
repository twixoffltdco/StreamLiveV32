<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rss_vk.php';
$base = rss_vk_base();
$site = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$user = trim((string)($_GET['user'] ?? $_GET['username'] ?? ''));
$limit = min(200, max(10, (int)($_GET['limit'] ?? 100)));
$items = [];
$title = $site . ' — Профили';
$link = $base . '/';
try {
  if ($user !== '') {
    $st = db()->prepare('SELECT id, username, created_at FROM users WHERE username = ? LIMIT 1');
    $st->execute([$user]);
    $u = $st->fetch();
    if ($u) {
      $title = $site . ' — @' . $u['username'];
      $link = $base . '/profile?username=' . rawurlencode($u['username']);
      $uid = (int)$u['id'];
      // posts
      try {
        $p = db()->prepare('SELECT fp.id, fp.message, fp.created_at, ft.id AS tid, ft.title FROM forum_posts fp JOIN forum_threads ft ON ft.id=fp.thread_id WHERE fp.user_id=? ORDER BY fp.id DESC LIMIT ?');
        $p->bindValue(1, $uid, PDO::PARAM_INT);
        $p->bindValue(2, $limit, PDO::PARAM_INT);
        $p->execute();
        foreach ($p->fetchAll() ?: [] as $row) {
          $items[] = [
            'title' => 'Пост в: ' . $row['title'],
            'link' => $base . '/forum_thread.php?id=' . (int)$row['tid'] . '#post-' . (int)$row['id'],
            'guid' => $base . '/forum_post/' . (int)$row['id'],
            'description' => mb_substr(strip_tags((string)$row['message']), 0, 300),
            'pub_date' => $row['created_at'],
            'image' => '',
          ];
        }
      } catch (Throwable $e) {}
    }
  } else {
    $st = db()->query("SELECT id, username, created_at FROM users ORDER BY id DESC LIMIT $limit");
    foreach ($st->fetchAll() ?: [] as $u) {
      $l = $base . '/profile?username=' . rawurlencode($u['username']);
      $items[] = [
        'title' => '@' . $u['username'],
        'link' => $l,
        'guid' => $l,
        'description' => 'Профиль на ' . $site,
        'pub_date' => $u['created_at'] ?? null,
        'image' => '',
      ];
    }
  }
} catch (Throwable $e) {}
rss_vk_render(['title' => $title, 'link' => $link, 'description' => $title], $items);
