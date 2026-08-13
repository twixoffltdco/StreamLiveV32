<?php
declare(strict_types=1);
$studio_title = 'Мои каналы';
$studio_active = 'channel';
$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
$user = current_user();
if (!$user) { header('Location: /login.php'); exit; }
$uid = (int)$user['id'];
$rows = [];
try {
  $st = db()->prepare('SELECT id, title, slug, status, views, paid_content FROM channels WHERE owner_id = ? ORDER BY id DESC');
  $st->execute([$uid]);
  $rows = $st->fetchAll() ?: [];
} catch (Throwable $e) {}
require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Мои каналы</h1>
<p class="st-sub">Управление эфиром и настройками — в карточке канала</p>
<div class="st-panel">
  <?php if (!$rows): ?>
    <p class="muted">Каналов пока нет.</p>
  <?php else: foreach ($rows as $c): ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 0;border-bottom:1px solid var(--st-border)">
      <div style="flex:1;min-width:160px">
        <strong><?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?></strong>
        <div class="muted"><?= htmlspecialchars($c['status'] ?? '', ENT_QUOTES, 'UTF-8') ?><?= !empty($c['paid_content']) ? ' · платный' : '' ?> · <?= (int)($c['views'] ?? 0) ?> просм.</div>
      </div>
      <a class="btn btn-outline" href="/channel.php?slug=<?= htmlspecialchars(urlencode($c['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">Открыть</a>
      <a class="btn btn-primary" href="/channel_manage.php?id=<?= (int)$c['id'] ?>">Управление</a>
      <a class="btn btn-outline" href="/platforma/studio/subscribers.php?channel_id=<?= (int)$c['id'] ?>">Подписчики</a>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>
