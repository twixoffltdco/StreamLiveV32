<?php
declare(strict_types=1);
$studio_title = 'Контент';
$studio_active = 'content';
require_once dirname(__DIR__) . '/api_bootstrap.php';
$pdo = pl_pdo();
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) @session_start();
$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
$rows = [];
try {
    if ($pdo) {
        $vTable = pl_find_table($pdo, ['stream_videos', 'videos']);
        if ($vTable) {
            $cols = pl_columns($pdo, $vTable);
            $idc = pl_pick_col($cols, ['id']);
            $tc = pl_pick_col($cols, ['title', 'name']);
            $st = $pdo->query("SELECT * FROM `$vTable` ORDER BY " . ($idc ? "`$idc` DESC" : '1') . " LIMIT 50");
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [
                    'id' => $idc ? $r[$idc] : '',
                    'title' => $tc ? (string)$r[$tc] : 'Видео',
                ];
            }
        }
    }
} catch (Throwable $e) {}
require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Контент канала</h1>
<p class="st-sub">Список видео · стиль Studio</p>
<div class="st-panel">
  <?php if (!$rows): ?>
    <p class="muted">Видео не найдены или таблица пуста.</p>
    <a class="btn btn-blue" href="/video_import.php">Импорт</a>
  <?php else: ?>
  <table class="st-table">
    <thead><tr><th>Название</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['title']) ?></td>
        <td><a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/video.php?id=<?= urlencode((string)$r['id']) ?>">Открыть</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_layout_end.php'; ?>
