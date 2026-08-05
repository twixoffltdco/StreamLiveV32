<?php
/**
 * Shorts / вертикальная лента каналов (как TikTok).
 * Раньше файла не было → /shorts.php отдавал 404 и «ничего не грузит».
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$__user = current_user();

// Берём одобренные каналы; если есть рекомендации — они в начале
$channels = [];
try {
  if (function_exists('recommended_channels') && $__user) {
    $channels = recommended_channels($__user, 0, 40);
  }
} catch (Throwable $e) {
  $channels = [];
}

if (!$channels) {
  try {
    $channels = db()->query(
      "SELECT * FROM channels WHERE status = 'approved' ORDER BY views DESC, id DESC LIMIT 40"
    )->fetchAll() ?: [];
  } catch (Throwable $e) {
    $channels = [];
  }
}

// Нет каналов — заглушка
if (!$channels) {
  $pageTitle = 'Shorts';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Shorts</h2><p>Пока нет каналов для ленты.</p><a class="btn btn-primary" href="/catalog.php">В каталог</a></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

// Открываем ленту через channel.php (тот же UI + рекомендации)
$first = $channels[0];
$slug = trim((string)($first['slug'] ?? ''));
$id = (int)($first['id'] ?? 0);

if ($slug !== '') {
  header('Location: /channel.php?slug=' . rawurlencode($slug) . '&from=shorts');
  exit;
}
if ($id > 0) {
  header('Location: /channel.php?id=' . $id . '&from=shorts');
  exit;
}

header('Location: /catalog.php');
exit;
