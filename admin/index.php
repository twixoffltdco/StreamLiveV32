<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php require_once __DIR__ . '/../includes/stats.php'; ?>
<h2>Обзор</h2>
<?php
$pending = (int)db()->query("SELECT COUNT(*) c FROM channels WHERE status='pending'")->fetch()['c'];
$totalChannels = (int)db()->query("SELECT COUNT(*) c FROM channels")->fetch()['c'];
$approvedChannels = (int)db()->query("SELECT COUNT(*) c FROM channels WHERE status='approved'")->fetch()['c'];
$users = (int)db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];

$forumThreads = 0; $forumPosts = 0; $bcChannels = 0; $bcSubs = 0;
try { $forumThreads = (int)db()->query("SELECT COUNT(*) c FROM forum_threads")->fetch()['c']; } catch (\Throwable $e) {}
try { $forumPosts = (int)db()->query("SELECT COUNT(*) c FROM forum_posts")->fetch()['c']; } catch (\Throwable $e) {}
try { $bcChannels = (int)db()->query("SELECT COUNT(*) c FROM broadcast_channels")->fetch()['c']; } catch (\Throwable $e) {}
try { $bcSubs = (int)db()->query("SELECT COUNT(*) c FROM broadcast_subscribers")->fetch()['c']; } catch (\Throwable $e) {}

$stats = stats_summary(30);
$maxDayViews = 1;
foreach ($stats['by_day'] as $d) { $maxDayViews = max($maxDayViews, (int)$d['c']); }
?>

<h3 style="margin:0 0 12px">Трафик (последние 30 дней)</h3>
<div class="stat-grid">
  <div class="stat-card"><div class="num"><?= number_format($stats['recent_views'], 0, '', ' ') ?></div><div class="lbl">Просмотров за 30 дней</div></div>
  <div class="stat-card"><div class="num"><?= number_format($stats['unique_visitors'], 0, '', ' ') ?></div><div class="lbl">Уникальных IP за 30 дней</div></div>
  <div class="stat-card"><div class="num"><?= $stats['avg_daily'] ?></div><div class="lbl">В среднем в день</div></div>
</div>
<div class="stat-grid">
  <div class="stat-card"><div class="num"><?= number_format($stats['today_views'], 0, '', ' ') ?></div><div class="lbl">Просмотров сегодня</div></div>
  <div class="stat-card"><div class="num"><?= number_format($stats['total_views'], 0, '', ' ') ?></div><div class="lbl">Всего просмотров за всё время</div></div>
  <div class="stat-card"><a href="/stats.php" target="_blank" class="btn btn-outline btn-sm" style="margin-top:6px">Публичная страница статистики →</a></div>
</div>

<?php if ($stats['by_day']): ?>
<div class="form-card form-wide" style="margin:20px 0">
  <h3 style="margin-top:0">Просмотры по дням</h3>
  <div style="display:flex;align-items:flex-end;gap:3px;height:120px;overflow-x:auto">
    <?php foreach ($stats['by_day'] as $d): ?>
      <div title="<?= e($d['d']) ?>: <?= (int)$d['c'] ?> просмотров, <?= (int)$d['u'] ?> уник."
           style="flex:0 0 auto;width:14px;height:<?= max(3, round(((int)$d['c'] / $maxDayViews) * 110)) ?>px;background:var(--accent-grad);border-radius:3px 3px 0 0"></div>
    <?php endforeach; ?>
  </div>
  <p style="color:var(--text-dim);font-size:11px;margin-top:8px">Наведите на столбик, чтобы увидеть дату и цифры. Период: <?= e($stats['by_day'][0]['d'] ?? '') ?> — <?= e(end($stats['by_day'])['d'] ?? '') ?></p>
</div>
<?php endif; ?>

<?php if ($stats['top_paths']): ?>
<div class="form-card form-wide" style="margin:20px 0">
  <h3 style="margin-top:0">Популярные страницы (30 дней)</h3>
  <table class="admin-table">
    <thead><tr><th>Страница</th><th>Просмотров</th></tr></thead>
    <tbody>
      <?php foreach ($stats['top_paths'] as $p): ?>
        <tr><td><?= e($p['path']) ?></td><td><?= (int)$p['c'] ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h3 style="margin:24px 0 12px">Платформа</h3>
<div class="stat-grid">
  <div class="stat-card"><div class="num"><?= $pending ?></div><div class="lbl">Ожидают модерации</div></div>
  <div class="stat-card"><div class="num"><?= $totalChannels ?></div><div class="lbl">Всего каналов (<?= $approvedChannels ?> одобрено)</div></div>
  <div class="stat-card"><div class="num"><?= $users ?></div><div class="lbl">Пользователей</div></div>
</div>
<div class="stat-grid">
  <div class="stat-card"><div class="num"><?= $forumThreads ?></div><div class="lbl">Тем на форуме</div></div>
  <div class="stat-card"><div class="num"><?= $forumPosts ?></div><div class="lbl">Сообщений на форуме</div></div>
  <div class="stat-card"><div class="num"><?= $bcChannels ?></div><div class="lbl">Каналов-рассылок (<?= $bcSubs ?> подписок)</div></div>
</div>

<?php if ($pending > 0): ?>
  <a href="/admin/moderation.php" class="btn btn-primary">Перейти к модерации (<?= $pending ?>)</a>
<?php endif; ?>

<h3 style="margin:30px 0 12px">Последняя активность</h3>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="admin-activity-grid">
  <div class="form-card form-wide">
    <h4 style="margin-top:0">Новые пользователи</h4>
    <?php $recentUsers = db()->query('SELECT username, email, created_at FROM users ORDER BY id DESC LIMIT 8')->fetchAll(); ?>
    <?php foreach ($recentUsers as $u): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px solid var(--border);font-size:12.5px">
        <span><?= e($u['username']) ?></span><span style="color:var(--text-dim)"><?= e($u['created_at']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="form-card form-wide">
    <h4 style="margin-top:0">Новые каналы</h4>
    <?php $recentChannels = db()->query('SELECT title, status, created_at FROM channels ORDER BY id DESC LIMIT 8')->fetchAll(); ?>
    <?php foreach ($recentChannels as $c): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px solid var(--border);font-size:12.5px">
        <span><?= e($c['title']) ?> <span class="status-pill status-<?= e($c['status']) ?>" style="font-size:10px"><?= e($c['status']) ?></span></span>
        <span style="color:var(--text-dim)"><?= e($c['created_at']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<style>@media (max-width: 720px) { .admin-activity-grid { grid-template-columns: 1fr !important; } }</style>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
