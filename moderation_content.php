<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/content_moderation.php';
cmod_ensure_schema();
$u = current_user();
if (!cmod_is_moderator($u)) {
  http_response_code(403);
  echo 'Доступ только модераторам и админам';
  exit;
}
if (session_status() === PHP_SESSION_NONE) @session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) csrf_verify();
  $r = cmod_vote($u, (int)($_POST['queue_id'] ?? 0), (string)($_POST['vote'] ?? 'approve'));
  $_SESSION['cmod_flash'] = $r['ok']
    ? ('Голос принят' . (!empty($r['final']) ? ' · итог: ' . $r['final'] : ' · нужно ещё голосов'))
    : ('Ошибка: ' . $r['error']);
  header('Location: /moderation_content.php');
  exit;
}

$type = (string)($_GET['type'] ?? '');
$items = [];
try {
  $sql = "SELECT q.*, u.username FROM content_moderation_queue q
          JOIN users u ON u.id = q.user_id
          WHERE q.status = 'pending' AND q.target_type IN ('thread','post')";
  $params = [];
  if (in_array($type, ['thread', 'post'], true)) {
    $sql .= ' AND q.target_type = ?';
    $params[] = $type;
  }
  $sql .= ' ORDER BY q.id ASC LIMIT 100';
  $st = db()->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  $items = [];
  $_SESSION['cmod_flash'] = 'Очередь: ' . $e->getMessage();
}
$flash = $_SESSION['cmod_flash'] ?? '';
unset($_SESSION['cmod_flash']);
$can = cmod_can_act($u);
$pageTitle = 'Модерация форума';
require __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:920px;margin:20px auto">
  <h1>Модерация форума</h1>
  <p style="opacity:.75">Только темы и сообщения форума (BBCode). Комментарии к видео не модерируются.</p>
  <?php if ($flash): ?><div style="padding:12px;margin:12px 0;border-radius:10px;background:rgba(34,211,238,.12)"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if (!$can['ok']): ?><div style="padding:12px;margin:12px 0;border-radius:10px;background:rgba(251,113,133,.12)"><?= htmlspecialchars($can['error'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <p><a href="?">Все</a> · <a href="?type=thread">Темы</a> · <a href="?type=post">Сообщения</a></p>
  <?php if (!$items): ?><p style="opacity:.7">Очередь пуста.</p><?php endif; ?>
  <?php foreach ($items as $it): ?>
    <div style="border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:14px;margin:12px 0">
      <div style="font-size:12px;opacity:.65">#<?= (int)$it['id'] ?> · <?= htmlspecialchars((string)$it['target_type'], ENT_QUOTES, 'UTF-8') ?> #<?= (int)$it['target_id'] ?> · @<?= htmlspecialchars((string)$it['username'], ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-weight:700;margin:6px 0"><?= htmlspecialchars((string)($it['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      <div style="opacity:.85;font-size:14px;white-space:pre-wrap"><?= htmlspecialchars((string)($it['snippet'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      <?php if ($can['ok']): ?>
      <form method="post" style="display:flex;gap:8px;margin-top:10px">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <input type="hidden" name="queue_id" value="<?= (int)$it['id'] ?>">
        <button name="vote" value="approve" type="submit">Одобрить</button>
        <button name="vote" value="reject" type="submit">Отклонить</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
