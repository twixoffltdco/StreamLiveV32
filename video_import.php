<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__ . '/includes/premiere_helpers.php')) require_once __DIR__ . '/includes/premiere_helpers.php';
if (is_file(__DIR__ . '/includes/content_moderation.php')) { require_once __DIR__ . '/includes/content_moderation.php'; try { cmod_ensure_schema(); } catch (Throwable $e) {} }
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/video_embed.php';
$__user = require_login();

$channelId = (int)($_GET['channel_id'] ?? $_POST['channel_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM channels WHERE id = ? AND owner_id = ?');
$stmt->execute([$channelId, $__user['id']]);
$channel = $stmt->fetch();
if (!$channel) { http_response_code(404); require_once __DIR__ . '/includes/header.php'; echo '<div class="container"><p>Канал не найден или это не ваш канал</p></div>'; require_once __DIR__ . '/includes/footer.php'; exit; }

$error = null;
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? 'preview';
  $sourceUrl = trim((string)($_POST['source_url'] ?? ''));

  if ($sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
    $error = 'Вставьте корректную ссылку на видео (YouTube, VK, Rutube, Dropbox, любой сайт…)';
  } else {
    $platform = detect_video_platform($sourceUrl);
    if (!$platform) {
      // крайний fallback
      $platform = 'iframe';
    }
    if ($platform) {
      $embedUrl = normalize_video_embed($platform, $sourceUrl);

      if ($action === 'preview') {
        $meta = fetch_video_meta($sourceUrl, $platform);
        $preview = [
          'source_url' => $sourceUrl,
          'platform'   => $platform,
          'embed_url'  => $embedUrl,
          'title'      => $meta['title'] ?: '',
          'description'=> $meta['description'] ?: '',
          'thumbnail'  => $meta['thumbnail_url'] ?: '',
          'tags'       => $meta['tags'] !== '' ? $meta['tags'] : guess_video_tags($meta['title'], $meta['description']),
          'meta_source'=> $meta['meta_source'],
        ];
      } else {
        $title = trim(mb_substr($_POST['title'] ?? '', 0, 255));
        $description = trim(mb_substr($_POST['description'] ?? '', 0, 5000));
        $tags = trim(mb_substr($_POST['tags'] ?? '', 0, 500));
        $thumbnail = trim((string)($_POST['thumbnail'] ?? ''));
        $metaSource = in_array($_POST['meta_source'] ?? '', ['oembed','opengraph'], true) ? $_POST['meta_source'] : 'manual';

        if ($title === '') {
          $error = 'Название не может быть пустым';
          $preview = ['source_url' => $sourceUrl, 'platform' => $platform, 'embed_url' => $embedUrl, 'title' => $title, 'description' => $description, 'tags' => $tags, 'thumbnail' => $thumbnail, 'meta_source' => $metaSource];
        } else {
          $slug = substr(bin2hex(random_bytes(6)), 0, 10);
          $stmt = db()->prepare(
            'INSERT INTO videos (channel_id, user_id, slug, source_url, platform, embed_url, title, description, tags, thumbnail_url, meta_source, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
          );
          // ВСЕГДА в модерацию: status=pending → в каталоге не видно, пока 2 модератора не одобрят
          $vidStatus = 'pending';
          $stmt->execute([$channelId, $__user['id'], $slug, $sourceUrl, $platform, $embedUrl, $title, $description, $tags, $thumbnail ?: null, $metaSource, $vidStatus]);
          $newId = (int)db()->lastInsertId();
          $premAt = trim((string)($_POST['premiere_at'] ?? ''));
          $premEnd = trim((string)($_POST['premiere_end_at'] ?? ''));
          if ($premAt !== '' && $newId > 0) {
            try {
              if (function_exists('premiere_apply')) {
                premiere_apply($newId, $channelId, $premAt, $premEnd);
              } else {
                db()->prepare("UPDATE videos SET is_premiere=1, premiere_at=?, premiere_end_at=? WHERE id=?")
                  ->execute([$premAt, $premEnd !== '' ? $premEnd : null, $newId]);
              }
              // premiere_apply мог снова выставить published — вернуть pending
              try { db()->prepare("UPDATE videos SET status='pending' WHERE id=?")->execute([$newId]); } catch (Throwable $e) {}
            } catch (Throwable $e) {}
          }
          if ($newId > 0) {
            if (function_exists('cmod_enqueue')) {
              try { cmod_enqueue('video', $newId, (int)$__user['id'], $title, mb_substr($description, 0, 300)); } catch (Throwable $e) {}
            } else {
              try { db()->prepare("UPDATE videos SET mod_status='pending' WHERE id=?")->execute([$newId]); } catch (Throwable $e) {}
            }
          }
          flash_set('success', 'Видео на модерации. В каталоге появится после одобрения двумя модераторами.');
          redirect('/channel_manage.php?id=' . (int)$channelId);
        }
      }
    }
  }
}

$pageTitle = 'Импорт видео';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:640px">
  <h1>Импорт видео в канал «<?= e($channel['title']) ?>»</h1>

  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <?php if (!$preview): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="channel_id" value="<?= $channelId ?>">
      <input type="hidden" name="action" value="preview">
      <label>Ссылка на видео (YouTube, VK, RuTube, TikTok, Twitch, Одноклассники, russtube.ru/нашютуб.рф (и любой клон PlayTube PHP), PeerTube, Facebook, Twitter/X, Coub, BitChute, Google Drive, Streamable, Reddit, .mp4, .m3u8)</label>
      <input type="url" name="source_url" placeholder="https://..." required style="width:100%;padding:10px;margin:8px 0">
      <button type="submit" class="btn btn-primary">Распознать</button>
    <div style="margin:10px 0"><label>Премьера (опц.)</label><br><input type="datetime-local" name="premiere_at"> <input type="datetime-local" name="premiere_end_at"></div></form>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="channel_id" value="<?= $channelId ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="source_url" value="<?= e($preview['source_url']) ?>">
      <input type="hidden" name="meta_source" value="<?= e($preview['meta_source']) ?>">
      <p style="color:var(--text-dim);font-size:13px">
        Платформа: <b><?= e($preview['platform']) ?></b> ·
        автозаполнение:
        <b><?= $preview['meta_source'] === 'none' ? 'не удалось, заполните вручную' : $preview['meta_source'] ?></b>
      </p>

      <p style="color:var(--text-dim);font-size:12.5px;margin-bottom:4px">Предпросмотр — так это увидят зрители. Если видео не воспроизводится здесь, скорее всего оно не будет работать и после публикации.</p>
      <?php render_player_embed($preview['platform'], $preview['embed_url'] ?? $preview['source_url'], $preview['source_url'], 'importPreviewPlayer'); ?>

      <input type="hidden" name="thumbnail" value="<?= e($preview['thumbnail']) ?>">

      <label>Название</label>
      <input type="text" name="title" value="<?= e($preview['title']) ?>" required style="width:100%;padding:10px;margin:8px 0">

      <label>Описание</label>
      <textarea name="description" rows="4" style="width:100%;padding:10px;margin:8px 0"><?= e($preview['description']) ?></textarea>

      <label>Теги (через запятую)</label>
      <input type="text" name="tags" value="<?= e($preview['tags']) ?>" style="width:100%;padding:10px;margin:8px 0">

      <div style="margin:12px 0;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,.1)">
        <label style="font-weight:600">Премьера (необязательно)</label>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
          <div><span style="font-size:12px;opacity:.7">Начало</span><br><input type="datetime-local" name="premiere_at" style="padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.3);color:inherit"></div>
          <div><span style="font-size:12px;opacity:.7">Конец (опц.)</span><br><input type="datetime-local" name="premiere_end_at" style="padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(0,0,0,.3);color:inherit"></div>
        </div>
        <p style="font-size:12px;opacity:.65;margin:8px 0 0">Если указать — зрители ждут старт (как премьера YouTube).</p>
      </div>
      <button type="submit" class="btn btn-primary">Опубликовать видео</button>
    </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
