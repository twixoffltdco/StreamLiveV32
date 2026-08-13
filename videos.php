<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__ . '/includes/recommendations.php')) require_once __DIR__ . '/includes/recommendations.php';
if (is_file(__DIR__ . '/includes/premiere_helpers.php')) require_once __DIR__ . '/includes/premiere_helpers.php';
if (function_exists('premiere_ensure_columns')) premiere_ensure_columns();
if (function_exists('premiere_mark_used_if_ended')) premiere_mark_used_if_ended();
if (is_file(__DIR__ . '/includes/premiere_visibility.php')) require_once __DIR__ . '/includes/premiere_visibility.php';
if (is_file(__DIR__ . '/includes/video_thumb.php')) require_once __DIR__ . '/includes/video_thumb.php';

$q = trim((string)($_GET['q'] ?? ''));
$cat = trim((string)($_GET['cat'] ?? ''));
$pageTitle = $q !== '' ? 'Поиск: ' . $q : ($cat !== '' ? 'Видео: ' . $cat : 'Видео');
require_once __DIR__ . '/includes/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;
$categories = [
  '' => 'Все', 'музыка' => 'Музыка', 'игры' => 'Игры', 'новости' => 'Новости',
  'спорт' => 'Спорт', 'юмор' => 'Юмор', 'обучение' => 'Обучение', 'кино' => 'Кино', 'live' => 'Live',
];
$searchTerm = $q !== '' ? $q : $cat;
$videos = [];
try {
  $fetch = min(150, $perPage * 5);
  if ($searchTerm !== '') {
    $like = '%' . $searchTerm . '%';
    $stmt = db()->prepare(
      "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug, c.status AS channel_status
       FROM videos v JOIN channels c ON c.id = v.channel_id
       WHERE (v.status = 'published' OR v.status = 'scheduled' OR v.status IS NULL)
         AND c.status = 'approved'
         AND (v.title LIKE ? OR v.description LIKE ? OR v.tags LIKE ?)
       ORDER BY COALESCE(v.created_at, v.id) DESC LIMIT ?"
    );
    $stmt->execute([$like, $like, $like, $fetch]);
    $videos = $stmt->fetchAll() ?: [];
  if (function_exists('premiere_should_show_in_videos')) {
    $videos = array_values(array_filter($videos, 'premiere_should_show_in_videos'));
  }
  } else {
    $stmt = db()->prepare(
      "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug, c.status AS channel_status
       FROM videos v JOIN channels c ON c.id = v.channel_id
       WHERE (v.status = 'published' OR v.status = 'scheduled' OR v.status IS NULL)
         AND c.status = 'approved'
       ORDER BY COALESCE(v.created_at, v.id) DESC LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $fetch, PDO::PARAM_INT);
    $stmt->bindValue(2, max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    $videos = $stmt->fetchAll() ?: [];
  }
} catch (Throwable $e) {
  // fallback без channel_status
  try {
    $stmt = db()->prepare(
      "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug
       FROM videos v JOIN channels c ON c.id = v.channel_id
       WHERE v.status = 'published' ORDER BY v.id DESC LIMIT 48"
    );
    $stmt->execute();
    $videos = $stmt->fetchAll() ?: [];
  } catch (Throwable $e2) { $videos = []; }
}

if (function_exists('premiere_filter_videos_list')) {
  $videos = premiere_filter_videos_list($videos);
}
$videos = array_slice($videos, 0, $perPage);
$replayOn = function_exists('premiere_is_replay_window') && premiere_is_replay_window();
?>
<div class="container" style="max-width:1100px;margin:20px auto">
  <h1 style="margin:0 0 8px">Видео</h1>
  <?php
  if (is_file(__DIR__ . '/includes/recommendations.php') && function_exists('render_recommendations_section')) {
    echo '<div style="margin:12px 0 18px">';
    try { render_recommendations_section('videos', 8); } catch (Throwable $e) {}
    echo '</div>';
  } elseif (is_file(__DIR__ . '/platforma/recommendations_block.php')) {
    echo '<div style="margin:12px 0 18px">';
    try { include __DIR__ . '/platforma/recommendations_block.php'; } catch (Throwable $e) {}
    echo '</div>';
  }
  ?>
  <?php if ($replayOn): ?><p style="font-size:13px;opacity:.75">🔁 Повтор премьер: 00:00–05:00 МСК</p><?php endif; ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 16px">
    <?php foreach ($categories as $key => $label): ?>
      <a href="/videos.php<?= $key !== '' ? '?cat='.rawurlencode($key) : '' ?>"
         style="padding:6px 12px;border-radius:999px;font-size:13px;text-decoration:none;border:1px solid rgba(255,255,255,.12);<?= ($cat===$key && $q==='')?'background:rgba(167,139,250,.25)':'' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" style="margin-bottom:18px;display:flex;gap:8px">
    <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Поиск…" style="flex:1;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
    <button type="submit" class="btn btn-primary">Найти</button>
  </form>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
    <?php foreach ($videos as $v):
      $badge = function_exists('premiere_badge') ? premiere_badge($v) : '';
      $thumb = function_exists('video_thumb_url') ? video_thumb_url($v) : (string)($v['thumbnail_url'] ?? '');
      $canvasSrc = function_exists('video_canvas_src') ? video_canvas_src($v) : '';
      if ($canvasSrc === '') {
        $eu = (string)($v['embed_url'] ?? '');
        $su = (string)($v['source_url'] ?? '');
        if (preg_match('/\.(mp4|webm|m3u8)($|\?)/i', $eu)) $canvasSrc = $eu;
        elseif (preg_match('/\.(mp4|webm|m3u8)($|\?)/i', $su)) $canvasSrc = $su;
      }
      $imgSrc = $thumb !== '' ? $thumb : '/assets/img/video-placeholder.png';
    ?>
      <a href="/video.php?slug=<?= htmlspecialchars(urlencode((string)$v['slug']), ENT_QUOTES, 'UTF-8') ?>"
         style="text-decoration:none;color:inherit;display:block;border-radius:12px;overflow:hidden;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03)">
        <div data-thumb-canvas data-thumb-src="<?= htmlspecialchars($canvasSrc, ENT_QUOTES, 'UTF-8') ?>" style="aspect-ratio:16/9;background:#111;position:relative;overflow:hidden">
          <img src="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:block" loading="lazy" referrerpolicy="no-referrer">
          <?php if ($badge === 'premiere_upcoming'): ?>
            <span style="position:absolute;left:8px;top:8px;font-size:11px;padding:3px 8px;border-radius:6px;background:rgba(167,139,250,.95);color:#0b0b12;font-weight:700">Премьера</span>
          <?php elseif ($badge === 'premiere_live'): ?>
            <span style="position:absolute;left:8px;top:8px;font-size:11px;padding:3px 8px;border-radius:6px;background:#f00;color:#fff;font-weight:700">● В эфире</span>
          <?php elseif ($badge === 'premiere_replay'): ?>
            <span style="position:absolute;left:8px;top:8px;font-size:11px;padding:3px 8px;border-radius:6px;background:rgba(34,211,238,.95);color:#0b0b12;font-weight:700">Повтор</span>
          <?php endif; ?>
        </div>
        <div style="padding:10px 12px">
          <div style="font-size:14px;font-weight:600;line-height:1.3"><?= htmlspecialchars((string)($v['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
          <div style="font-size:12px;opacity:.6;margin-top:4px"><?= htmlspecialchars((string)($v['channel_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$videos): ?>
    <p style="opacity:.7;margin-top:24px">Нет видео. Премьеры с <b>не одобренного</b> канала в общей ленте не показываются — только у владельца в студии/на канале.</p>
  <?php endif; ?>
</div>
<script src="/assets/js/video-thumb-canvas.js?v=3" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
