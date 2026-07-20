<?php
require_once __DIR__ . '/../includes/header.php';
require_login();

// Используем ту же систему, что уже назначает модераторов форума через
// /admin/forum.php (таблица forum_moderators) — не завожу вторую роль,
// чтобы не путать, кому какая панель доступна.
if (!is_forum_moderator($__user)) {
  http_response_code(403);
  die('Доступ только для модераторов форума. Назначить можно в /admin/forum.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'restore_thread') {
    db()->prepare('UPDATE forum_threads SET is_deleted = 0 WHERE id = ?')->execute([(int)$_POST['thread_id']]);
    flash_set('success', 'Тема восстановлена');
  } elseif ($action === 'restore_post') {
    db()->prepare('UPDATE forum_posts SET is_deleted = 0 WHERE id = ?')->execute([(int)$_POST['post_id']]);
    flash_set('success', 'Сообщение восстановлено');
  }
  redirect('/moderator/forum.php');
}

$deletedThreads = db()->query(
  "SELECT t.*, u.username, c.title AS category_title FROM forum_threads t
   JOIN users u ON u.id = t.user_id JOIN forum_categories c ON c.id = t.category_id
   WHERE t.is_deleted = 1 ORDER BY t.created_at DESC LIMIT 200"
)->fetchAll();

$deletedPosts = db()->query(
  "SELECT p.*, u.username, t.title AS thread_title FROM forum_posts p
   JOIN users u ON u.id = p.user_id JOIN forum_threads t ON t.id = p.thread_id
   WHERE p.is_deleted = 1 ORDER BY p.created_at DESC LIMIT 200"
)->fetchAll();
?>
<div class="container">
  <h1>Модерация форума — удалённое</h1>
  <p style="color:var(--text-dim);font-size:13px">Темы и сообщения, удалённые с форума, хранятся в базе (мягкое удаление) — их можно вернуть, либо стереть окончательно.</p>

  <h2>Удалённые темы (<?= count($deletedThreads) ?>)</h2>
  <?php if (!$deletedThreads): ?><p style="color:var(--text-dim)">Пусто</p><?php endif; ?>
  <?php foreach ($deletedThreads as $t): ?>
    <div class="card" style="padding:14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:10px">
      <div>
        <b><?= e($t['title']) ?></b>
        <div style="color:var(--text-dim);font-size:12px">раздел «<?= e($t['category_title']) ?>» · автор <?= e($t['username']) ?> · <?= e($t['created_at']) ?></div>
      </div>
      <div style="display:flex;gap:6px">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="restore_thread"><input type="hidden" name="thread_id" value="<?= (int)$t['id'] ?>"><button class="btn btn-ok btn-sm" type="submit">Восстановить</button></form>
      </div>
    </div>
  <?php endforeach; ?>

  <h2 style="margin-top:28px">Удалённые сообщения (<?= count($deletedPosts) ?>)</h2>
  <?php if (!$deletedPosts): ?><p style="color:var(--text-dim)">Пусто</p><?php endif; ?>
  <?php foreach ($deletedPosts as $p): ?>
    <div class="card" style="padding:14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:10px">
      <div>
        <div style="color:var(--text-dim);font-size:12px">тема «<?= e($p['thread_title']) ?>» · автор <?= e($p['username']) ?> · <?= e($p['created_at']) ?></div>
        <p style="margin:4px 0"><?= e(mb_substr($p['message'], 0, 200)) ?></p>
      </div>
      <div style="display:flex;gap:6px">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="restore_post"><input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-ok btn-sm" type="submit">Восстановить</button></form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
