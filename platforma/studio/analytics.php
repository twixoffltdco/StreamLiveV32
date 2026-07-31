<?php
declare(strict_types=1);
$studio_title = 'Аналитика';
$studio_active = 'analytics';
require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Аналитика</h1>
<p class="st-sub">Сводка · детальная статистика в StreamLife</p>
<div class="st-panel">
  <p class="muted">Полные графики и отчёты — в штатном dashboard StreamLife, чтобы не дублировать тяжёлую логику.</p>
  <div class="st-actions">
    <a class="btn btn-blue" href="/dashboard.php">dashboard.php</a>
    <a class="btn btn-outline" href="/stats.php">stats.php</a>
  </div>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>
