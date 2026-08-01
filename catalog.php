<?php
require_once __DIR__ . '/includes/header.php';

$type = $_GET['type'] ?? null;
$type = in_array($type, ['tv', 'radio'], true) ? $type : null;

$sql = "SELECT id, slug, title, logo_url, type, views FROM channels WHERE status = 'approved'";
$params = [];
if ($type) { $sql .= ' AND type = ?'; $params[] = $type; }
$sql .= ' ORDER BY views DESC, created_at DESC LIMIT 60';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$channels = $stmt->fetchAll();
?>
<?php
// Блок «Для вас» на каталоге (и в StreamLife, и в Платформе)
@include __DIR__ . '/platforma/recommendations_block.php';
?>
<div class="container">
  <div class="type-tabs">
    <a href="/catalog.php?type=tv" class="<?= $type === 'tv' ? 'active' : '' ?>">Телеканалы</a>
    <a href="/catalog.php?type=radio" class="<?= $type === 'radio' ? 'active' : '' ?>">Радио</a>
    <a href="/shorts.php" class="btn btn-outline btn-sm" style="margin-left:8px">▶ Shorts</a>
  </div>

  <?php if (empty($channels)): ?>
    <div class="empty-state">Пока тут пусто. Будь первым, кто запустит канал.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($channels as $c): ?>
      <a class="card" href="/channel.php?slug=<?= e($c['slug']) ?>">
        <div class="card-thumb">
          <span class="badge-live"><?= $c['type'] === 'radio' ? 'РАДИО' : 'LIVE' ?></span>
          <?php if ($c['logo_url']): ?><img src="<?= e($c['logo_url']) ?>" alt="<?= e($c['title']) ?>"><?php endif; ?>
        </div>
        <div class="card-body">
          <p class="card-title"><?= e($c['title']) ?></p>
          <p class="card-meta"><?= (int)$c['views'] ?> просмотров</p>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
