<?php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/xml; charset=utf-8');

$base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
if ($base === '' || strpos($base, 'freedev.app') !== false) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base = $scheme . '://' . $host;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

function sm_url(string $loc, ?string $lastmod = null, string $changefreq = 'daily', string $priority = '0.7'): void {
  echo '  <url><loc>' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '</loc>';
  if ($lastmod) echo '<lastmod>' . date('Y-m-d', strtotime($lastmod)) . '</lastmod>';
  echo '<changefreq>' . $changefreq . '</changefreq><priority>' . $priority . '</priority></url>' . "\n";
}

// Статичные страницы
sm_url($base . '/catalog.php', null, 'daily', '1.0');
sm_url($base . '/forum.php', null, 'daily', '0.8');
sm_url($base . '/shorts.php', null, 'daily', '0.6');
sm_url($base . '/smotrim.php', null, 'daily', '0.9');
sm_url($base . '/docs.php', null, 'monthly', '0.3');
sm_url($base . '/legal/terms.php', null, 'yearly', '0.2');
sm_url($base . '/legal/privacy.php', null, 'yearly', '0.2');
sm_url($base . '/legal/cookies.php', null, 'yearly', '0.2');

try {
  $pdo = db();

  // Все одобренные каналы (ТВ и радио) — и через классическую страницу, и через smotrim.php
  $stmt = $pdo->query("SELECT slug, created_at FROM channels WHERE status = 'approved' ORDER BY id DESC");
  foreach ($stmt->fetchAll() as $ch) {
    sm_url($base . '/channel-pc.php?slug=' . urlencode($ch['slug']), $ch['created_at'] ?? null, 'hourly', '0.9');
    sm_url($base . '/smotrim.php?slug=' . urlencode($ch['slug']), $ch['created_at'] ?? null, 'hourly', '0.9');
  }

  // Категории и темы форума
  $stmt = $pdo->query('SELECT id FROM forum_categories ORDER BY id ASC');
  foreach ($stmt->fetchAll() as $cat) {
    sm_url($base . '/forum_category.php?id=' . (int)$cat['id'], null, 'daily', '0.6');
  }
  $stmt = $pdo->query('SELECT id, created_at FROM forum_threads WHERE is_deleted = 0 ORDER BY id DESC LIMIT 2000');
  foreach ($stmt->fetchAll() as $th) {
    sm_url($base . '/forum_thread.php?id=' . (int)$th['id'], $th['created_at'] ?? null, 'weekly', '0.5');
  }
} catch (\Throwable $e) {
  // Если какая-то таблица недоступна — отдаём хотя бы статичные страницы выше, не 500-м
}


  // Видео
  try {
    $stmt = $pdo->query("SELECT id, created_at FROM videos WHERE status = 'approved' OR status IS NULL OR status = 'published' ORDER BY id DESC LIMIT 2000");
    foreach ($stmt->fetchAll() as $v) {
      sm_url($base . '/video.php?id=' . (int)$v['id'], $v['created_at'] ?? null, 'weekly', '0.7');
    }
  } catch (Throwable $e) {}

  // Ресурсы
  try {
    $stmt = $pdo->query("SELECT id, created_at FROM resources WHERE status = 'approved' OR status = 'published' ORDER BY id DESC LIMIT 2000");
    foreach ($stmt->fetchAll() as $r) {
      sm_url($base . '/resource.php?id=' . (int)$r['id'], $r['created_at'] ?? null, 'weekly', '0.6');
    }
  } catch (Throwable $e) {}

echo '</urlset>';
