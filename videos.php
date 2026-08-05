<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
if (is_file(__DIR__ . '/includes/recommendations.php')) {
  require_once __DIR__ . '/includes/recommendations.php';
}

$q = trim((string)($_GET['q'] ?? ''));
$cat = trim((string)($_GET['cat'] ?? ''));
$pageTitle = $q !== '' ? 'Поиск: ' . $q : ($cat !== '' ? 'Видео: ' . $cat : 'Видео');
require_once __DIR__ . '/includes/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

// Категории (чипы как раньше)
$categories = [
  '' => 'Все',
  'музыка' => 'Музыка',
  'игры' => 'Игры',
  'новости' => 'Новости',
  'спорт' => 'Спорт',
  'юмор' => 'Юмор',
  'обучение' => 'Обучение',
  'кино' => 'Кино',
  'live' => 'Live',
];

$searchTerm = $q !== '' ? $q : $cat;

try {
  if ($searchTerm !== '') {
    // MATCH если есть FULLTEXT, иначе LIKE
    try {
      $stmt = db()->prepare(
        "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug FROM videos v
         JOIN channels c ON c.id = v.channel_id
         WHERE v.status = 'published' AND MATCH(v.title, v.description, v.tags) AGAINST (? IN NATURAL LANGUAGE MODE)
         ORDER BY v.created_at DESC LIMIT ? OFFSET ?"
      );
      $stmt->bindValue(1, $searchTerm);
      $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
      $stmt->bindValue(3, $offset, PDO::PARAM_INT);
      $stmt->execute();
      $videos = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
      $like = '%' . $searchTerm . '%';
      $stmt = db()->prepare(
        "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug FROM videos v
         JOIN channels c ON c.id = v.channel_id
         WHERE v.status = 'published' AND (v.title LIKE ? OR v.description LIKE ? OR v.tags LIKE ?)
         ORDER BY v.created_at DESC LIMIT ? OFFSET ?"
      );
      $stmt->execute([$like, $like, $like, $perPage, $offset]);
      $videos = $stmt->fetchAll() ?: [];
    }
  } else {
    $stmt = db()->prepare(
      "SELECT v.*, c.title AS channel_title, c.slug AS channel_slug FROM videos v
       JOIN channels c ON c.id = v.channel_id
       WHERE v.status = 'published' ORDER BY v.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $videos = $stmt->fetchAll() ?: [];
  }
} catch (Throwable $e) {
  $videos = [];
}
?>
<link rel="stylesheet" href="/assets/css/youtube-watch.css?v=3">
<div class="container yt-videos-page">
  <div class="yt-videos-head">
    <h1>Видео</h1>
    <form method="GET" class="yt-videos-search">
      <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск видео…">
      <button class="btn btn-outline btn-sm" type="submit">Найти</button>
    </form>
  </div>

  <div class="yt-cat-chips" role="navigation" aria-label="Категории">
    <?php foreach ($categories as $key => $label):
      $active = ($key === '' && $cat === '' && $q === '') || ($key !== '' && ($cat === $key || mb_strtolower($q) === $key));
      $href = $key === '' ? '/videos' : '/videos?cat=' . rawurlencode($key);
    ?>
      <a class="yt-chip<?= $active ? ' active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$videos): ?>
    <div class="empty-state">
      <p>Видео пока нет<?= $searchTerm !== '' ? ' по запросу «' . e($searchTerm) . '»' : '' ?>.</p>
    </div>
  <?php endif; ?>

  <div class="video-grid">
    <?php foreach ($videos as $v): ?>
      <a class="video-card" href="/video.php?slug=<?= e($v['slug']) ?>">
        <div class="video-card-thumb">
          <img src="<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>" alt="" loading="lazy" decoding="async" width="480" height="270">
        </div>
        <div class="video-card-body">
          <div class="video-card-title"><?= e($v['title']) ?></div>
          <div class="video-card-meta"><?= e($v['channel_title']) ?> · <?= (int)$v['views_count'] ?> просм.</div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:20px;display:flex;gap:8px">
    <?php
      $qs = http_build_query(array_filter(['q' => $q ?: null, 'cat' => $cat ?: null]));
      $base = '/videos' . ($qs ? '?' . $qs . '&' : '?');
    ?>
    <?php if ($page > 1): ?><a class="btn btn-outline btn-sm" href="<?= e($base) ?>page=<?= $page - 1 ?>">← Назад</a><?php endif; ?>
    <?php if (count($videos) === $perPage): ?><a class="btn btn-outline btn-sm" href="<?= e($base) ?>page=<?= $page + 1 ?>">Далее →</a><?php endif; ?>
  </div>
</div>
<?php if (function_exists('render_recommendations_section')): ?>
<div class="container"><?php render_recommendations_section('videos', 8); ?></div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
