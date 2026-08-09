<?php
/**
 * Единая панель модерации контента в КОРНЕ (без /moderation/ — для InfinityFree).
 * Админ и модер. Каналы — отдельно в admin/moderation и moderator/index.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/content_moderation.php';

$u = current_user();
$isStaff = false;
try {
  if (function_exists('cmod_is_moderator')) $isStaff = cmod_is_moderator($u);
  if (!$isStaff && is_array($u) && in_array(($u['role'] ?? ''), ['admin', 'moderator'], true)) $isStaff = true;
} catch (Throwable $e) {}

if (!$isStaff) {
  http_response_code(403);
  header('Location: /error.php?c=403');
  exit;
}

if (session_status() === PHP_SESSION_NONE) @session_start();
try { cmod_ensure_schema(); } catch (Throwable $e) {}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (function_exists('csrf_verify')) csrf_verify();
    $r = cmod_vote($u, (int)($_POST['queue_id'] ?? 0), (string)($_POST['vote'] ?? 'approve'));
    $flash = !empty($r['ok']) ? ('OK' . (!empty($r['final']) ? ': ' . $r['final'] : '')) : ('Ошибка: ' . ($r['error'] ?? ''));
  } catch (Throwable $e) {
    $flash = $e->getMessage();
  }
}

$type = (string)($_GET['type'] ?? '');
$items = [];
$err = '';
try {
  $sql = "SELECT q.*, u.username FROM content_moderation_queue q
          LEFT JOIN users u ON u.id = q.user_id WHERE q.status = 'pending'";
  $params = [];
  $allowed = ['thread', 'post', 'video'];
  if (in_array($type, $allowed, true)) {
    $sql .= ' AND q.target_type = ?';
    $params[] = $type;
  }
  $sql .= ' ORDER BY q.id ASC LIMIT 100';
  $st = db()->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  $err = $e->getMessage();
}

$can = ['ok' => true, 'error' => ''];
try { if (function_exists('cmod_can_act')) $can = cmod_can_act($u); } catch (Throwable $e) {}

$pageTitle = 'Модерация контента';
require __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:920px;margin:20px auto">
  <h1>Модерация контента</h1>
  <p style="opacity:.75">Темы, посты, видео из очереди. Каналы: <a href="/admin/moderation.php">админ</a> / <a href="/moderator/">модер</a></p>
  <?php if ($err): ?><div style="padding:12px;background:rgba(251,113,133,.15);border-radius:10px;margin:10px 0"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($flash): ?><div style="padding:12px;background:rgba(34,211,238,.12);border-radius:10px;margin:10px 0"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <p><a href="?">Все</a> · <a href="?type=thread">Темы</a> · <a href="?type=post">Посты</a> · <a href="?type=video">Видео</a></p>
  <?php if (!$items): ?><p style="opacity:.7">Очередь пуста</p><?php endif; ?>
  <?php foreach ($items as $it): ?>
    <div style="border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:14px;margin:12px 0">
      <div style="font-size:12px;opacity:.65">#<?= (int)$it['id'] ?> · <?= htmlspecialchars((string)$it['target_type'], ENT_QUOTES, 'UTF-8') ?> · @<?= htmlspecialchars((string)($it['username']??''), ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-weight:700;margin:6px 0"><?= htmlspecialchars((string)($it['title']??''), ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-size:14px;white-space:pre-wrap;opacity:.85"><?= htmlspecialchars((string)($it['snippet']??''), ENT_QUOTES, 'UTF-8') ?></div>
      <?php if (!empty($can['ok'])): ?>
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
