<?php
declare(strict_types=1);
$studio_title = 'Оформление канала';
$studio_active = 'channel';

require_once dirname(__DIR__) . '/api_bootstrap.php';
$pdo = pl_pdo();
if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) @session_start();
$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));

$channel = null;
$msg = '';

try {
    if ($pdo && $user_id > 0) {
        $chTable = pl_find_table($pdo, ['channels']);
        if ($chTable) {
            $cols = pl_columns($pdo, $chTable);
            $owner = pl_pick_col($cols, ['owner_id', 'user_id']);
            $idCol = pl_pick_col($cols, ['id']);
            $cid = isset($_GET['id']) ? (int)$_GET['id'] : 0;

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $owner) {
                $cid = (int)($_POST['id'] ?? 0);
                $title = trim((string)($_POST['title'] ?? ''));
                $desc = trim((string)($_POST['description'] ?? ''));
                $titleCol = pl_pick_col($cols, ['title', 'name']);
                $descCol = pl_pick_col($cols, ['description', 'about']);
                if ($cid && $titleCol) {
                    $sql = "UPDATE `$chTable` SET `$titleCol` = ?";
                    $params = [$title];
                    if ($descCol) {
                        $sql .= ", `$descCol` = ?";
                        $params[] = $desc;
                    }
                    $sql .= " WHERE `$idCol` = ? AND `$owner` = ?";
                    $params[] = $cid;
                    $params[] = $user_id;
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $msg = 'Сохранено';
                }
            }

            if ($owner) {
                if ($cid) {
                    $st = $pdo->prepare("SELECT * FROM `$chTable` WHERE `$idCol` = ? AND `$owner` = ? LIMIT 1");
                    $st->execute([$cid, $user_id]);
                } else {
                    $st = $pdo->prepare("SELECT * FROM `$chTable` WHERE `$owner` = ? ORDER BY `$idCol` DESC LIMIT 1");
                    $st->execute([$user_id]);
                }
                $channel = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    }
} catch (Throwable $e) {
    $msg = 'Ошибка: ' . $e->getMessage();
}

require __DIR__ . '/_layout.php';
$cols = $channel ? array_keys($channel) : [];
$titleVal = $channel['title'] ?? $channel['name'] ?? '';
$descVal = $channel['description'] ?? '';
$idVal = $channel['id'] ?? '';
?>
<h1 class="st-h1">Оформление канала</h1>
<p class="st-sub">Как Channel customization в YouTube Studio</p>

<?php if ($user_id <= 0): ?>
<div class="alert alert-info">Нужен вход в StreamLife.</div>
<a class="btn btn-blue" href="/auth/login.php">Войти</a>
<?php elseif (!$channel): ?>
<div class="alert alert-info">Канал не найден. Создай канал в StreamLife.</div>
<a class="btn btn-blue" href="/new_channel.php">Создать канал</a>
<a class="btn btn-outline" href="/channel_manage.php">channel_manage.php</a>
<?php else: ?>
<?php if ($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="st-panel">
  <h2>Основные данные</h2>
  <form method="post" action="">
    <input type="hidden" name="id" value="<?= htmlspecialchars((string)$idVal) ?>">
    <label class="f">Название</label>
    <input class="f" type="text" name="title" value="<?= htmlspecialchars((string)$titleVal) ?>" required>
    <label class="f">Описание</label>
    <textarea class="f" name="description"><?= htmlspecialchars((string)$descVal) ?></textarea>
    <div class="st-actions">
      <button type="submit" class="btn btn-blue">Сохранить</button>
      <a class="btn btn-outline" href="/channel_manage.php?id=<?= urlencode((string)$idVal) ?>">Полные настройки StreamLife</a>
      <a class="btn btn-outline" href="/channel.php?id=<?= urlencode((string)$idVal) ?>">Открыть канал</a>
    </div>
  </form>
</div>

<div class="st-panel">
  <h2>Дополнительно</h2>
  <p class="muted">Лого, обложка, SEO, RTMP — в полном channel_manage StreamLife (чтобы не дублировать сложную логику и не сломать сайт).</p>
  <a class="btn btn-white" href="/channel_manage.php?id=<?= urlencode((string)$idVal) ?>">Открыть channel_manage.php</a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>
