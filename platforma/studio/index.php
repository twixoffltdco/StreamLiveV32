<?php
declare(strict_types=1);
$studio_title = 'Панель студии';
$studio_active = 'dashboard';

require_once dirname(__DIR__) . '/api_bootstrap.php';
$pdo = pl_pdo();
$site = pl_site_web();

if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) @session_start();
$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));

$views = 0; $subs = 0; $videos = 0; $live = 0; $channels = 0;
$recent = [];

try {
    if ($pdo && $user_id > 0) {
        $chTable = pl_find_table($pdo, ['channels']);
        if ($chTable) {
            $cols = pl_columns($pdo, $chTable);
            $owner = pl_pick_col($cols, ['owner_id', 'user_id', 'uid']);
            $viewCol = pl_pick_col($cols, ['views', 'viewers']);
            $liveCol = pl_pick_col($cols, ['last_stream_live', 'is_live']);
            $idCol = pl_pick_col($cols, ['id']);
            $titleCol = pl_pick_col($cols, ['title', 'name']);
            if ($owner) {
                $st = $pdo->prepare("SELECT * FROM `$chTable` WHERE `$owner` = ? ORDER BY `$idCol` DESC LIMIT 20");
                $st->execute([$user_id]);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $channels++;
                    if ($viewCol) $views += (int)($row[$viewCol] ?? 0);
                    if ($liveCol && !empty($row[$liveCol])) $live++;
                    $recent[] = [
                        'id' => $idCol ? $row[$idCol] : '',
                        'title' => $titleCol ? (string)$row[$titleCol] : 'Канал',
                        'views' => $viewCol ? (int)$row[$viewCol] : 0,
                    ];
                }
            }
        }
        $vTable = pl_find_table($pdo, ['stream_videos', 'videos']);
        if ($vTable) {
            $vcols = pl_columns($pdo, $vTable);
            $vo = pl_pick_col($vcols, ['user_id', 'owner_id', 'channel_id']);
            // count roughly
            try {
                $videos = (int)$pdo->query("SELECT COUNT(*) FROM `$vTable`")->fetchColumn();
            } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) {}

require __DIR__ . '/_layout.php';
?>
<h1 class="st-h1">Панель управления каналом</h1>
<p class="st-sub">Стиль YouTube Studio · данные из StreamLife</p>

<?php if ($user_id <= 0): ?>
<div class="alert alert-info">Войди в аккаунт StreamLife, чтобы видеть статистику своего канала.</div>
<a class="btn btn-blue" href="/auth/login.php">Войти</a>
<?php else: ?>

<div class="st-cards">
  <div class="st-card"><div class="lbl">Просмотры каналов</div><div class="val"><?= number_format($views) ?></div></div>
  <div class="st-card"><div class="lbl">Ваши каналы</div><div class="val"><?= (int)$channels ?></div></div>
  <div class="st-card"><div class="lbl">В эфире</div><div class="val"><?= (int)$live ?></div></div>
  <div class="st-card"><div class="lbl">Видео (всего на сайте)</div><div class="val"><?= number_format($videos) ?></div></div>
</div>

<div class="st-panel">
  <h2>Быстрые действия</h2>
  <div class="st-actions">
    <a class="btn btn-blue" href="/platforma/studio/channel.php">Оформление канала</a>
    <a class="btn btn-white" href="/platforma/studio/import.php">Импорт видео</a>
    <a class="btn btn-outline" href="/platforma/studio/content.php">Контент</a>
    <a class="btn btn-outline" href="/new_channel.php">Новый канал (StreamLife)</a>
  </div>
</div>

<div class="st-panel">
  <h2>Ваши каналы</h2>
  <?php if (!$recent): ?>
    <p class="muted">Каналов пока нет. Создай через StreamLife или открой channel_manage.</p>
  <?php else: ?>
  <table class="st-table">
    <thead><tr><th>Канал</th><th>Просмотры</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['title']) ?></td>
        <td><?= number_format($r['views']) ?></td>
        <td><a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel.php?id=<?= urlencode((string)$r['id']) ?>">Открыть</a>
            <a class="btn btn-outline" style="padding:6px 12px;font-size:12px" href="/channel_manage.php?id=<?= urlencode((string)$r['id']) ?>">Настройки</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
