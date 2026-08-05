<?php
declare(strict_types=1);
/**
 * Импорт видео внутри Студии Platforma.
 * Использует includes/video_embed.php (парсеры платформ), UI — только студия.
 */
$studio_title = 'Импорт видео';
$studio_active = 'import';

$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/video_embed.php';
if (is_file($root . '/includes/notify.php')) {
  require_once $root . '/includes/notify.php';
}

$user = function_exists('current_user') ? current_user() : null;
if (!$user) {
  header('Location: /login.php?redirect=' . rawurlencode('/platforma/studio/import.php'));
  exit;
}
$uid = (int)$user['id'];

$channelId = (int)($_GET['channel_id'] ?? $_POST['channel_id'] ?? 0);
$channels = [];
try {
  $st = db()->prepare('SELECT id, title, slug FROM channels WHERE owner_id = ? ORDER BY id DESC');
  $st->execute([$uid]);
  $channels = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

$error = null;
$success = null;
$preview = null;

function studio_make_video_slug(string $title): string {
  $s = mb_strtolower($title);
  $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
  $s = trim($s, '-');
  if ($s === '') $s = 'video';
  return mb_substr($s, 0, 60) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $channels) {
  if (function_exists('csrf_verify')) csrf_verify();
  $action = (string)($_POST['action'] ?? 'preview');
  $channelId = (int)($_POST['channel_id'] ?? 0);
  $okCh = false;
  foreach ($channels as $c) {
    if ((int)$c['id'] === $channelId) { $okCh = true; break; }
  }
  if (!$okCh) {
    $error = 'Выберите свой канал';
  } else {
    $sourceUrl = trim((string)($_POST['source_url'] ?? ''));
    if ($sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
      $error = 'Вставьте корректную ссылку на видео';
    } else {
      $platform = function_exists('detect_video_platform') ? detect_video_platform($sourceUrl) : null;
      if (!$platform) $platform = 'iframe';
      $embedUrl = function_exists('normalize_video_embed')
        ? normalize_video_embed($platform, $sourceUrl)
        : $sourceUrl;

      if ($action === 'preview') {
        $meta = function_exists('fetch_video_meta')
          ? fetch_video_meta($sourceUrl, $platform)
          : ['title' => '', 'description' => '', 'thumbnail_url' => '', 'tags' => '', 'meta_source' => 'none'];
        $tags = $meta['tags'] ?? '';
        if ($tags === '' && function_exists('guess_video_tags')) {
          $tags = guess_video_tags($meta['title'] ?? '', $meta['description'] ?? '');
        }
        $preview = [
          'source_url' => $sourceUrl,
          'platform' => $platform,
          'embed_url' => $embedUrl,
          'title' => $meta['title'] ?? '',
          'description' => $meta['description'] ?? '',
          'thumbnail' => $meta['thumbnail_url'] ?? '',
          'tags' => $tags,
          'meta_source' => $meta['meta_source'] ?? 'none',
          'channel_id' => $channelId,
        ];
      } else {
        // save
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 255));
        $description = trim(mb_substr((string)($_POST['description'] ?? ''), 0, 5000));
        $tags = trim(mb_substr((string)($_POST['tags'] ?? ''), 0, 500));
        $thumbnail = trim((string)($_POST['thumbnail'] ?? ''));
        $metaSource = in_array($_POST['meta_source'] ?? '', ['oembed', 'opengraph', 'manual'], true)
          ? $_POST['meta_source'] : 'manual';
        $status = 'published';
        try {
          // moderation if needed
          $st = db()->query("SHOW COLUMNS FROM videos LIKE 'status'");
        } catch (Throwable $e) {}

        if ($title === '') {
          $error = 'Название не может быть пустым';
          $preview = [
            'source_url' => $sourceUrl,
            'platform' => $platform,
            'embed_url' => $embedUrl,
            'title' => $title,
            'description' => $description,
            'thumbnail' => $thumbnail,
            'tags' => $tags,
            'meta_source' => $metaSource,
            'channel_id' => $channelId,
          ];
        } else {
          $slug = studio_make_video_slug($title);
          try {
            db()->prepare(
              'INSERT INTO videos (channel_id, user_id, slug, source_url, platform, embed_url, title, description, tags, thumbnail_url, meta_source, status)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
              $channelId, $uid, $slug, $sourceUrl, $platform, $embedUrl,
              $title, $description, $tags, $thumbnail ?: null, $metaSource, $status,
            ]);
            $success = 'Видео опубликовано';
            if (function_exists('notify_event')) {
              try { notify_event('video_published', ['slug' => $slug, 'title' => $title]); } catch (Throwable $e) {}
            }
            header('Location: /platforma/studio/content.php?ok=1');
            exit;
          } catch (Throwable $e) {
            // without meta_source column
            try {
              db()->prepare(
                'INSERT INTO videos (channel_id, user_id, slug, source_url, platform, embed_url, title, description, tags, thumbnail_url, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
              )->execute([
                $channelId, $uid, $slug, $sourceUrl, $platform, $embedUrl,
                $title, $description, $tags, $thumbnail ?: null, $status,
              ]);
              header('Location: /platforma/studio/content.php?ok=1');
              exit;
            } catch (Throwable $e2) {
              $error = 'Не удалось сохранить: ' . $e2->getMessage();
              $preview = [
                'source_url' => $sourceUrl,
                'platform' => $platform,
                'embed_url' => $embedUrl,
                'title' => $title,
                'description' => $description,
                'thumbnail' => $thumbnail,
                'tags' => $tags,
                'meta_source' => $metaSource,
                'channel_id' => $channelId,
              ];
            }
          }
        }
      }
    }
  }
}

require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Импорт видео</h1>
<p class="st-sub">Ссылка → превью (обложка, название, описание) → публикация. Поддержка платформ через парсер студии.</p>

<?php if ($error): ?>
  <div class="st-panel" style="border-color:rgba(251,113,133,.4);color:#fda4af"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="st-panel" style="border-color:rgba(34,211,238,.4)"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!$channels): ?>
  <div class="st-panel muted">Сначала создайте канал на сайте, затем вернитесь в студию.</div>
<?php elseif (!$preview): ?>
  <div class="st-panel">
    <h2>Ссылка на видео</h2>
    <p class="muted" style="margin-bottom:12px">YouTube, VK, Rutube, TikTok, Twitch, Dropbox, Instagram, прямой mp4/m3u8, iframe и другие URL с OG/oEmbed.</p>
    <form method="post">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="action" value="preview">
      <label class="muted" style="display:block;margin-bottom:6px">Канал</label>
      <select name="channel_id" required style="width:100%;max-width:400px;padding:10px;margin-bottom:12px">
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $channelId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <label class="muted" style="display:block;margin-bottom:6px">URL</label>
      <input type="url" name="source_url" required placeholder="https://…" style="width:100%;padding:12px;margin-bottom:14px">
      <button type="submit" class="btn btn-primary">Получить превью</button>
    </form>
  </div>
<?php else: ?>
  <div class="st-panel">
    <h2>Превью · <?= htmlspecialchars($preview['platform'], ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="muted">Мета: <?= htmlspecialchars((string)$preview['meta_source'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php if (!empty($preview['thumbnail'])): ?>
      <img src="<?= htmlspecialchars($preview['thumbnail'], ENT_QUOTES, 'UTF-8') ?>" alt="" style="max-width:320px;width:100%;border-radius:12px;aspect-ratio:16/9;object-fit:cover;background:#000;margin:10px 0">
    <?php endif; ?>
    <div style="position:relative;width:100%;max-width:640px;aspect-ratio:16/9;background:#000;border-radius:12px;overflow:hidden;margin:12px 0">
      <?php if (($preview['platform'] ?? '') === 'mp4'): ?>
        <video src="<?= htmlspecialchars($preview['embed_url'], ENT_QUOTES, 'UTF-8') ?>" controls playsinline style="width:100%;height:100%"></video>
      <?php else: ?>
        <iframe src="<?= htmlspecialchars($preview['embed_url'], ENT_QUOTES, 'UTF-8') ?>" allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>
      <?php endif; ?>
    </div>
    <form method="post">
      <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="channel_id" value="<?= (int)$preview['channel_id'] ?>">
      <input type="hidden" name="source_url" value="<?= htmlspecialchars($preview['source_url'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="thumbnail" value="<?= htmlspecialchars($preview['thumbnail'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="meta_source" value="<?= htmlspecialchars($preview['meta_source'] ?? 'manual', ENT_QUOTES, 'UTF-8') ?>">
      <label class="muted">Название</label>
      <input name="title" required value="<?= htmlspecialchars($preview['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:12px;margin:6px 0 12px">
      <label class="muted">Описание</label>
      <textarea name="description" rows="4" style="width:100%;padding:12px;margin:6px 0 12px"><?= htmlspecialchars($preview['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      <label class="muted">Теги</label>
      <input name="tags" value="<?= htmlspecialchars($preview['tags'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%;padding:12px;margin:6px 0 14px">
      <div class="st-actions">
        <button type="submit" class="btn btn-primary">Опубликовать</button>
        <a class="btn btn-outline" href="/platforma/studio/import.php">Другая ссылка</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
