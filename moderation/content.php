<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/content_moderation.php';
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
    ? ('Голос принят' . (!empty($r['final']) ? ' · итог: ' . $r['final'] : ' · нужно ещё голосов (2 approve)'))
    : ('Ошибка: ' . $r['error']);
  header('Location: /moderation/content.php');
  exit;
}

$type = (string)($_GET['type'] ?? '');
$sql = "SELECT q.*, u.username FROM content_moderation_queue q JOIN users u ON u.id = q.user_id WHERE q.status = 'pending'";
$params = [];
if (in_array($type, ['thread', 'post', 'video'], true)) {
  $sql .= ' AND q.target_type = ?';
  $params[] = $type;
}
$sql .= ' ORDER BY q.id ASC LIMIT 100';
$st = db()->prepare($sql);
$st->execute($params);
$items = $st->fetchAll() ?: [];
$flash = $_SESSION['cmod_flash'] ?? '';
unset($_SESSION['cmod_flash']);
$can = cmod_can_act($u);
$pageTitle = 'Модерация контента';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="container" style="max-width:920px;margin:20px auto">
  <h1>Модерация: темы, сообщения, видео</h1>
  <p style="opacity:.75">Контент <b>всех</b> (включая модеров и админов) идёт в очередь. Своё модерировать нельзя. Одобрение/отклонение — голоса <b>двух разных</b> модераторов. Лимит модера: 1 действие / 100 ч.</p>
  <?php if ($flash): ?><div style="padding:12px;margin:12px 0;border-radius:10px;background:rgba(34,211,238,.12)"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if (!$can['ok']): ?><div style="padding:12px;margin:12px 0;border-radius:10px;background:rgba(251,113,133,.12)"><?= htmlspecialchars($can['error'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <p>
    <a href="?">Все</a> ·
    <a href="?type=thread">Темы</a> ·
    <a href="?type=post">Сообщения</a> ·
    <a href="?type=video">Видео</a>
  </p>
  <?php if (!$items): ?><p style="opacity:.7">Очередь пуста.</p><?php endif; ?>
  <?php foreach ($items as $it): ?>
    <div style="padding:14px;margin-bottom:12px;border-radius:12px;border:1px solid rgba(255,255,255,.1)">
      <div style="font-size:12px;opacity:.6"><?= htmlspecialchars($it['target_type']) ?> #<?= (int)$it['target_id'] ?> · <?= htmlspecialchars($it['username']) ?> · +<?= (int)$it['approve_count'] ?> / −<?= (int)$it['reject_count'] ?></div>
      <div style="font-weight:600;margin:6px 0"><?= htmlspecialchars((string)$it['title'], ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-size:13px;opacity:.85;white-space:pre-wrap"><?= htmlspecialchars((string)$it['snippet'], ENT_QUOTES, 'UTF-8') ?></div>
      <?php if ($it['target_type'] === 'thread'): ?>
        <a href="/forum_thread.php?id=<?= (int)$it['target_id'] ?>" target="_blank">Тема</a>
      <?php elseif ($it['target_type'] === 'video'): ?>
        <a href="/video.php?id=<?= (int)$it['target_id'] ?>" target="_blank">Видео</a>
      <?php endif; ?>
      <form method="post" style="margin-top:10px;display:flex;gap:8px">
        <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
        <input type="hidden" name="queue_id" value="<?= (int)$it['id'] ?>">
        <button type="submit" name="vote" value="approve" <?= $can['ok']?'':'disabled' ?>>Одобрить</button>
        <button type="submit" name="vote" value="reject" <?= $can['ok']?'':'disabled' ?>>Отклонить</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
