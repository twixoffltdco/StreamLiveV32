<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Клипы / Shorts';
$items = [];
try {
  $items = db()->query("SELECT v.*, c.title AS channel_title FROM videos v
    LEFT JOIN channels c ON c.id = v.channel_id
    WHERE v.is_short = 1 AND (v.status = 'published' OR v.status IS NULL)
    ORDER BY v.id DESC LIMIT 40")->fetchAll() ?: [];
} catch (Throwable $e) {}
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h1>Клипы</h1>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin:16px 0">
    <?php foreach ($items as $v): ?>
      <a href="/video.php?slug=<?= e(urlencode($v['slug'])) ?>" style="display:block;border-radius:12px;overflow:hidden;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08)">
        <?php if (!empty($v['thumbnail_url'])): ?>
          <img src="<?= e($v['thumbnail_url']) ?>" alt="" style="width:100%;aspect-ratio:9/16;object-fit:cover">
        <?php else: ?>
          <div style="aspect-ratio:9/16;display:flex;align-items:center;justify-content:center;opacity:.5">▶</div>
        <?php endif; ?>
        <div style="padding:8px;font-size:13px"><?= e(mb_substr($v['title'],0,60)) ?></div>
      </a>
    <?php endforeach; ?>
    <?php if (!$items): ?><p style="opacity:.7">Клипов пока нет. Откройте видео → «Создать клип».</p><?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
