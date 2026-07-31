<?php
declare(strict_types=1);
$studio_title = 'Импорт видео';
$studio_active = 'import';
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) @session_start();
$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));

require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Импорт видео</h1>
<p class="st-sub">Интерфейс в стиле Studio · обработка через StreamLife video_import</p>

<?php if ($user_id <= 0): ?>
<div class="alert alert-info">Войди, чтобы импортировать.</div>
<a class="btn btn-blue" href="/auth/login.php">Войти</a>
<?php else: ?>

<div class="st-panel">
  <h2>Импорт</h2>
  <p class="muted" style="margin-bottom:16px">Форма ниже открывает штатный <code>video_import.php</code> StreamLife — так ничего не ломается на сервере. Эта страница — оболочка Studio.</p>
  <div class="st-actions">
    <a class="btn btn-blue" href="/video_import.php">Открыть импорт StreamLife</a>
    <a class="btn btn-outline" href="/videos.php">Мои видео</a>
  </div>
</div>

<div class="st-panel">
  <h2>Как это устроено</h2>
  <p class="muted">Загрузка/импорт завязаны на antibot, moderation и схему БД StreamLife. Мы не подменяем этот код (из‑за этого был риск 500), а даём Studio-оболочку и быстрый переход.</p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
