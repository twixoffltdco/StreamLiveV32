<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stats.php';
$__user = current_user();

$stats30 = stats_summary(30);
$stats90 = stats_summary(90);

$totalChannels = (int)db()->query("SELECT COUNT(*) c FROM channels WHERE status='approved'")->fetch()['c'];
$totalUsers = (int)db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];

$maxDayViews = 1;
foreach ($stats30['by_day'] as $d) { $maxDayViews = max($maxDayViews, (int)$d['c']); }

$pageTitle = 'Статистика посещаемости';
$seoDescription = 'Открытая статистика посещаемости ' . SITE_NAME . ' — для рекламных сетей и партнёров';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h1 style="margin:24px 0 6px">📊 Статистика посещаемости</h1>
  <p style="color:var(--text-dim);font-size:13px;max-width:600px">
    Открытые цифры посещаемости <?= e(SITE_NAME) ?> — считаются автоматически по реальным
    просмотрам страниц (без ботов и служебных запросов). Обновляется в реальном времени.
  </p>

  <div class="stat-grid" style="margin-top:20px">
    <div class="stat-card"><div class="num"><?= number_format($stats30['recent_views'], 0, '', ' ') ?></div><div class="lbl">Просмотров за 30 дней</div></div>
    <div class="stat-card"><div class="num"><?= number_format($stats30['unique_visitors'], 0, '', ' ') ?></div><div class="lbl">Уникальных посетителей за 30 дней</div></div>
    <div class="stat-card"><div class="num"><?= $stats30['avg_daily'] ?></div><div class="lbl">В среднем просмотров в день</div></div>
  </div>
  <div class="stat-grid">
    <div class="stat-card"><div class="num"><?= number_format($stats90['recent_views'], 0, '', ' ') ?></div><div class="lbl">Просмотров за 90 дней</div></div>
    <div class="stat-card"><div class="num"><?= $totalChannels ?></div><div class="lbl">Активных ТВ/радио каналов</div></div>
    <div class="stat-card"><div class="num"><?= $totalUsers ?></div><div class="lbl">Зарегистрированных пользователей</div></div>
  </div>

  <?php if ($stats30['by_day']): ?>
  <div class="form-card form-wide" style="margin:24px 0">
    <h3 style="margin-top:0">Динамика просмотров за 30 дней</h3>
    <div style="display:flex;align-items:flex-end;gap:3px;height:140px;overflow-x:auto">
      <?php foreach ($stats30['by_day'] as $d): ?>
        <div title="<?= e($d['d']) ?>: <?= (int)$d['c'] ?> просмотров"
             style="flex:0 0 auto;width:16px;height:<?= max(3, round(((int)$d['c'] / $maxDayViews) * 130)) ?>px;background:var(--accent-grad);border-radius:3px 3px 0 0"></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <p style="color:var(--text-dim);font-size:12px;margin:20px 0 40px">
    Методика подсчёта: считаются реальные загрузки страниц сайта, без учёта фоновых AJAX-запросов
    (обновление чата, расписания и т.п.) и без учёта администраторских разделов.
  </p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
