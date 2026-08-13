<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
$items = [];
try {
  $rows = db()->query("SELECT slug, title FROM videos WHERE status='published' ORDER BY id DESC LIMIT 8")->fetchAll() ?: [];
  foreach ($rows as $r) {
    $items[] = [
      'type' => 'video',
      'title' => (string)$r['title'],
      'url' => '/video.php?slug=' . rawurlencode((string)$r['slug']),
    ];
  }
} catch (Throwable $e) {}
try {
  $rows = db()->query('SELECT id, title FROM forum_threads ORDER BY id DESC LIMIT 6')->fetchAll() ?: [];
  foreach ($rows as $r) {
    $items[] = [
      'type' => 'forum',
      'title' => (string)$r['title'],
      'url' => '/forum_thread.php?id=' . (int)$r['id'],
    ];
  }
} catch (Throwable $e) {
  try {
    $rows = db()->query('SELECT id, title FROM threads ORDER BY id DESC LIMIT 6')->fetchAll() ?: [];
    foreach ($rows as $r) {
      $items[] = ['type'=>'forum','title'=>(string)$r['title'],'url'=>'/forum_thread.php?id='.(int)$r['id']];
    }
  } catch (Throwable $e2) {}
}
if (!$items) {
  $items[] = ['type'=>'home','title'=>'Главная платформы','url'=>'/'];
}
echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
