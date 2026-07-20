<?php
require_once __DIR__ . '/_layout_start.php';
require_once __DIR__ . '/../includes/migrations.php';

$log = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $log = array_merge(run_charset_fix(db()), run_url_fix(db()), run_migrations(db()));
  flash_set('success', 'База данных обновлена');
}

$migrations = list_migrations(db());
$pending = count(array_filter($migrations, fn($m) => !$m['applied']));
?>
<h2>Обновление базы данных</h2>
<p style="color:var(--text-dim);font-size:13px">
  Нажмите «Обновить», чтобы: 1) принудительно выставить кодировку utf8mb4 на базе и всех таблицах
  (чинит проблему с «кракозябрами» на хостингах, где БД была создана с другой кодировкой), и
  2) применить новые миграции — новые таблицы/поля, которые появляются при обновлении версии StreamLive.
  Операция полностью безопасна и идемпотентна — можно нажимать сколько угодно раз.
</p>

<form method="POST" style="margin:20px 0">
  <?= csrf_field() ?>
  <button class="btn btn-primary" type="submit">Обновить базу данных <?php if ($pending): ?>(<?= $pending ?> новых миграций)<?php endif; ?></button>
</form>

<?php if ($log !== null): ?>
  <div class="form-card form-wide" style="margin:0 0 24px">
    <h3 style="margin-top:0">Результат</h3>
    <pre style="background:var(--bg-elevated);padding:14px;border-radius:10px;font-size:13px;white-space:pre-wrap"><?php foreach ($log as $line) { echo e($line) . "\n"; } ?></pre>
  </div>
<?php endif; ?>

<h3>Миграции</h3>
<table class="admin-table">
  <thead><tr><th>Файл</th><th>Статус</th></tr></thead>
  <tbody>
    <?php foreach ($migrations as $m): ?>
    <tr>
      <td><?= e($m['filename']) ?></td>
      <td><span class="status-pill <?= $m['applied'] ? 'status-approved' : 'status-pending' ?>"><?= $m['applied'] ? 'применена' : 'ожидает' ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($migrations)): ?><tr><td colspan="2" style="color:var(--text-dim)">Миграций пока нет</td></tr><?php endif; ?>
  </tbody>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
