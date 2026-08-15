<?php
/**
 * Что нового — без 500 на любом хосте.
 */
try {
  require_once __DIR__ . '/includes/functions.php';
  require_once __DIR__ . '/includes/auth.php';
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Bootstrap error';
  exit;
}

$pageTitle = 'Что нового';
$data = ['threads' => [], 'videos' => [], 'channels' => [], 'at' => date('c')];

try {
  $cacheFile = __DIR__ . '/storage/cache/platform_whats_new.json';
  $ttl = 600;
  $useCache = is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl);
  if ($useCache) {
    $tmp = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($tmp)) $data = array_merge($data, $tmp);
  } else {
    try {
      $data['threads'] = db()->query(
        "SELECT t.id, t.title, t.created_at, u.username FROM forum_threads t
         JOIN users u ON u.id=t.user_id
         WHERE t.is_deleted=0
           AND (t.mod_status IS NULL OR t.mod_status='' OR t.mod_status='approved' OR t.mod_status='0')
         ORDER BY t.created_at DESC LIMIT 12"
      )->fetchAll() ?: [];
    } catch (Throwable $e) {
      try {
        $data['threads'] = db()->query(
          "SELECT t.id, t.title, t.created_at, u.username FROM forum_threads t
           JOIN users u ON u.id=t.user_id WHERE t.is_deleted=0 ORDER BY t.created_at DESC LIMIT 12"
        )->fetchAll() ?: [];
      } catch (Throwable $e2) {}
    }
    try {
      $data['videos'] = db()->query(
        "SELECT id, slug, title, created_at FROM videos
         WHERE (status='published' OR status IS NULL OR status='')
           AND (mod_status IS NULL OR mod_status='' OR mod_status='approved' OR mod_status='0')
         ORDER BY id DESC LIMIT 12"
      )->fetchAll() ?: [];
    } catch (Throwable $e) {
      try {
        $data['videos'] = db()->query(
          "SELECT id, slug, title, created_at FROM videos WHERE status='published' OR status IS NULL ORDER BY id DESC LIMIT 12"
        )->fetchAll() ?: [];
      } catch (Throwable $e2) {}
    }
    try {
      $data['channels'] = db()->query(
        "SELECT id, slug, title, created_at FROM channels WHERE status='approved' OR status IS NULL ORDER BY id DESC LIMIT 12"
      )->fetchAll() ?: [];
    } catch (Throwable $e) {}
    $data['at'] = date('c');
    try {
      if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0755, true);
      @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {}
  }
} catch (Throwable $e) {}

try {
  require_once __DIR__ . '/includes/header.php';
} catch (Throwable $e) {
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Что нового</title></head><body>';
  echo '<h1>Что нового</h1><p>Шапка временно недоступна.</p>';
}

echo '<div class="container" style="padding:16px">';
echo '<h1>Что нового на платформе</h1>';
echo '<p style="opacity:.65;font-size:13px">Обновлено ' . htmlspecialchars((string)($data['at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-top:16px">';

echo '<section class="form-card" style="padding:14px"><h2 style="font-size:16px">Форум</h2>';
foreach ($data['threads'] as $t) {
  echo '<div style="margin-bottom:8px"><a href="/forum_thread.php?id=' . (int)$t['id'] . '">' . htmlspecialchars((string)$t['title'], ENT_QUOTES, 'UTF-8') . '</a>';
  echo '<div style="font-size:11px;opacity:.6">@' . htmlspecialchars((string)($t['username'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></div>';
}
if (!$data['threads']) echo '<p style="opacity:.6">Пока пусто</p>';
echo '</section>';

echo '<section class="form-card" style="padding:14px"><h2 style="font-size:16px">Видео</h2>';
foreach ($data['videos'] as $v) {
  echo '<div style="margin-bottom:8px"><a href="/video.php?slug=' . htmlspecialchars(urlencode((string)($v['slug'] ?? '')), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)($v['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</a></div>';
}
if (!$data['videos']) echo '<p style="opacity:.6">Пока пусто</p>';
echo '</section>';

echo '<section class="form-card" style="padding:14px"><h2 style="font-size:16px">Каналы</h2>';
foreach ($data['channels'] as $c) {
  echo '<div style="margin-bottom:8px"><a href="/channel.php?slug=' . htmlspecialchars(urlencode((string)($c['slug'] ?? '')), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)($c['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</a></div>';
}
if (!$data['channels']) echo '<p style="opacity:.6">Пока пусто</p>';
echo '</section>';

echo '</div></div>';
try { require_once __DIR__ . '/includes/footer.php'; } catch (Throwable $e) { echo '</body></html>'; }
