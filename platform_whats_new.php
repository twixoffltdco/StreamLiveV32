<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Что нового на платформе';
$cacheFile = __DIR__ . '/storage/cache/platform_whats_new.json';
$ttl = 600; // 10 мин — редко, без нагрузки
$data = null;
if (is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
  $data = json_decode((string)file_get_contents($cacheFile), true);
}
if (!$data) {
  $data = ['threads' => [], 'videos' => [], 'channels' => [], 'at' => date('c')];
  try {
    $data['threads'] = db()->query("SELECT t.id, t.title, t.created_at, u.username FROM forum_threads t JOIN users u ON u.id=t.user_id WHERE t.is_deleted=0 ORDER BY t.created_at DESC LIMIT 12")->fetchAll() ?: [];
  } catch (Throwable $e) {}
  try {
    $data['videos'] = db()->query("SELECT id, slug, title, created_at, thumbnail_url FROM videos WHERE status='published' OR status IS NULL ORDER BY id DESC LIMIT 12")->fetchAll() ?: [];
  } catch (Throwable $e) {}
  try {
    $data['channels'] = db()->query("SELECT id, slug, title, created_at FROM channels WHERE status='approved' OR status IS NULL ORDER BY id DESC LIMIT 12")->fetchAll() ?: [];
  } catch (Throwable $e) {}
  if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0755, true);
  @file_put_contents($cacheFile, json_encode($data));
}
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h1>Что нового на платформе</h1>
  <p style="opacity:.65;font-size:13px">Кэш ~10 мин · обновлено <?= e($data['at'] ?? '') ?></p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:16px">
    <section class="form-card" style="padding:14px">
      <h2 style="font-size:16px;margin:0 0 10px">Форум</h2>
      <?php foreach ($data['threads'] as $t): ?>
        <div style="margin-bottom:8px;font-size:14px"><a href="/forum_thread.php?id=<?= (int)$t['id'] ?>"><?= e($t['title']) ?></a>
          <div style="font-size:11px;opacity:.6">@<?= e($t['username']??'') ?> · <?= e($t['created_at']??'') ?></div></div>
      <?php endforeach; ?>
    </section>
    <section class="form-card" style="padding:14px">
      <h2 style="font-size:16px;margin:0 0 10px">Видео</h2>
      <?php foreach ($data['videos'] as $v): ?>
        <div style="margin-bottom:8px;font-size:14px"><a href="/video.php?slug=<?= e(urlencode($v['slug']??'')) ?>"><?= e($v['title']??'') ?></a></div>
      <?php endforeach; ?>
    </section>
    <section class="form-card" style="padding:14px">
      <h2 style="font-size:16px;margin:0 0 10px">Каналы</h2>
      <?php foreach ($data['channels'] as $c): ?>
        <div style="margin-bottom:8px;font-size:14px"><a href="/channel.php?id=<?= (int)$c['id'] ?>"><?= e($c['title']??'') ?></a></div>
      <?php endforeach; ?>
    </section>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
