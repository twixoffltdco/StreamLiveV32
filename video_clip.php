<?php
/** Создать клип/Short из видео (метаданные start/end, отдельная запись) */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$u = current_user();
if (!$u) { flash_set('error','Войдите'); redirect('/auth/login.php'); }

$id = (int)($_GET['id'] ?? $_POST['video_id'] ?? 0);
$st = db()->prepare('SELECT * FROM videos WHERE id = ?');
$st->execute([$id]);
$video = $st->fetch();
if (!$video) { flash_set('error','Видео не найдено'); redirect('/videos.php'); }

$ownerOk = (int)($video['user_id'] ?? 0) === (int)$u['id']
  || in_array(($u['role'] ?? ''), ['admin'], true);
if (!$ownerOk) { flash_set('error','Нет доступа'); redirect('/video.php?id='.$id); }

try {
  db()->exec('ALTER TABLE videos ADD COLUMN parent_video_id INT NULL');
  db()->exec('ALTER TABLE videos ADD COLUMN clip_start INT NULL');
  db()->exec('ALTER TABLE videos ADD COLUMN clip_end INT NULL');
  db()->exec('ALTER TABLE videos ADD COLUMN is_short TINYINT(1) NOT NULL DEFAULT 0');
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) csrf_verify();
  $start = max(0, (int)($_POST['start_sec'] ?? 0));
  $end = max($start + 1, (int)($_POST['end_sec'] ?? $start + 30));
  if ($end - $start > 180) $end = $start + 180; // max 3 min clip
  $title = trim((string)($_POST['title'] ?? '')) ?: ('Клип: ' . mb_substr((string)$video['title'], 0, 80));
  $slug = 'clip-' . $id . '-' . substr(md5($start . '-' . $end . microtime(true)), 0, 8);
  db()->prepare('INSERT INTO videos (channel_id, user_id, slug, title, description, source_url, platform, embed_url, thumbnail_url, status, parent_video_id, clip_start, clip_end, is_short)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)')
    ->execute([
      $video['channel_id'] ?? null,
      (int)$u['id'],
      $slug,
      $title,
      'Клип ' . $start . 's–' . $end . 's',
      $video['source_url'] ?? null,
      $video['platform'] ?? null,
      $video['embed_url'] ?? null,
      $video['thumbnail_url'] ?? null,
      'published',
      $id,
      $start,
      $end,
    ]);
  // notify fans of channel
  if (!empty($video['channel_id']) && is_file(__DIR__.'/includes/notify_event.php')) {
    require_once __DIR__.'/includes/notify_event.php';
    notify_channel_fans((int)$video['channel_id'], 'clip', 'Новый клип: '.$title, '/video.php?slug='.$slug);
  }
  flash_set('success', 'Клип создан');
  redirect('/video.php?slug=' . urlencode($slug));
}

$pageTitle = 'Создать клип';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:560px;margin:24px auto">
  <h1>Клип / Short</h1>
  <p style="opacity:.75">Из: <?= e($video['title']) ?></p>
  <form method="POST" class="form-card">
    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
    <input type="hidden" name="video_id" value="<?= (int)$id ?>">
    <label>Название</label>
    <input type="text" name="title" maxlength="200" placeholder="Клип…">
    <label>Начало (сек)</label>
    <input type="number" name="start_sec" min="0" value="0" required>
    <label>Конец (сек, макс. 180 сек длина)</label>
    <input type="number" name="end_sec" min="1" value="30" required>
    <button class="btn btn-primary" type="submit">Создать клип</button>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
