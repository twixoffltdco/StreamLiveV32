<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/service_helpers.php';
require_once __DIR__ . '/includes/bbcode.php';
$__user = current_user();

$threadId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
  'SELECT t.*, u.username, fc.title AS category_title FROM forum_threads t
   JOIN users u ON u.id = t.user_id
   JOIN forum_categories fc ON fc.id = t.category_id
   WHERE t.id = ? AND t.is_deleted = 0'
);
$stmt->execute([$threadId]);
$thread = $stmt->fetch();

if (!$thread) {
  http_response_code(404);
  $pageTitle = 'Тема не найдена';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Тема не найдена</h2><a href="/forum.php" class="btn btn-primary" style="margin-top:14px">На форум</a></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$isForumModerator = is_forum_moderator($__user);

// ---- Обработка форм (ответ, удаление сообщения, пин/лок) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? 'reply';

  if ($action === 'reply' && $__user && !$thread['is_locked']) {
    $message = trim(mb_substr($_POST['message'] ?? '', 0, 2000000));
    if ($message !== '') {
      db()->prepare('INSERT INTO forum_posts (thread_id, user_id, message) VALUES (?, ?, ?)')->execute([$threadId, $__user['id'], $message]);
      db()->prepare('UPDATE forum_threads SET last_post_at = NOW() WHERE id = ?')->execute([$threadId]);
    }
  } elseif ($action === 'delete_post' && $isForumModerator) {
    db()->prepare('UPDATE forum_posts SET is_deleted = 1 WHERE id = ? AND thread_id = ?')->execute([(int)$_POST['post_id'], $threadId]);
  } elseif ($action === 'toggle_pin' && $isForumModerator) {
    db()->prepare('UPDATE forum_threads SET is_pinned = NOT is_pinned WHERE id = ?')->execute([$threadId]);
  } elseif ($action === 'toggle_lock' && $isForumModerator) {
    db()->prepare('UPDATE forum_threads SET is_locked = NOT is_locked WHERE id = ?')->execute([$threadId]);
  } elseif ($action === 'delete_thread' && $isForumModerator) {
    db()->prepare('UPDATE forum_threads SET is_deleted = 1 WHERE id = ?')->execute([$threadId]);
    redirect('/forum_category.php?id=' . (int)$thread['category_id']);
  }
  redirect('/forum_thread.php?id=' . $threadId . '#posts');
}

db()->prepare('UPDATE forum_threads SET views = views + 1 WHERE id = ?')->execute([$threadId]);

$stmt = db()->prepare(
  'SELECT fp.*, u.username, u.role, u.avatar, u.is_verified, u.is_banned, u.gravatar_email FROM forum_posts fp
   JOIN users u ON u.id = fp.user_id
   WHERE fp.thread_id = ? AND fp.is_deleted = 0 ORDER BY fp.created_at ASC LIMIT 500'
);
$stmt->execute([$threadId]);
$posts = $stmt->fetchAll();

$pageTitle = $thread['title'] . ' — Форум';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <p style="margin:20px 0 4px">
    <a href="/forum.php" style="color:var(--accent-2);font-size:13px">Форум</a> ·
    <a href="/forum_category.php?id=<?= (int)$thread['category_id'] ?>" style="color:var(--accent-2);font-size:13px"><?= e($thread['category_title']) ?></a>
  </p>
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <h1 style="margin:0 0 20px">
      <?php if ($thread['is_pinned']): ?><span class="pin-badge">Закреплено</span><?php endif; ?>
      <?php if ($thread['is_locked']): ?><span class="lock-badge">Закрыто</span><?php endif; ?>
      <?= e($thread['title']) ?>
    </h1>
    <?php if ($isForumModerator): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_pin"><button class="btn btn-outline btn-sm" type="submit"><?= $thread['is_pinned'] ? 'Открепить' : 'Закрепить' ?></button></form>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_lock"><button class="btn btn-outline btn-sm" type="submit"><?= $thread['is_locked'] ? 'Открыть' : 'Закрыть' ?></button></form>
        <form method="POST" onsubmit="return confirm('Удалить тему целиком?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_thread"><button class="btn btn-danger btn-sm" type="submit">Удалить тему</button></form>
      </div>
    <?php endif; ?>
  </div>

  <div id="posts" class="forum-post-list">
    <?php foreach ($posts as $p): ?>
      <div class="forum-post">
        <div class="forum-post-author">
          <?= render_user_badge($p, 28) ?>
          <?php if ($p['role'] === 'admin'): ?><span class="role-badge">админ</span><?php endif; ?>
          <span style="color:var(--text-dim);font-size:12px"><?= e($p['created_at']) ?></span>
          <?php if ($__user && (int)$p['user_id'] !== (int)$__user['id']): ?>
            <a href="/messages?with=<?= (int)$p['user_id'] ?>" style="color:var(--accent-2);font-size:12px;margin-left:4px">написать</a>
          <?php endif; ?>
        </div>
        <?= banned_user_notice($p) ?>
        <div class="forum-post-body"><?= bbcode_to_html($p['message'], (int)$p['id']) ?></div>
        <?php if ($isForumModerator): ?>
          <form method="POST" onsubmit="return confirm('Удалить сообщение?')" style="margin-top:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_post">
            <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
            <button type="submit" style="background:none;border:none;color:var(--danger);font-size:11px;cursor:pointer">удалить сообщение</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($thread['is_locked']): ?>
    <p style="color:var(--text-dim);font-size:13px;margin-top:20px">Тема закрыта — новые ответы недоступны.</p>
  <?php elseif ($__user): ?>
    <form method="POST" class="form-card form-wide" style="margin:24px 0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reply">
      <label>Ваш ответ</label>
      <?php include __DIR__ . '/includes/bbcode_toolbar.php'; ?>
      <textarea id="bb-editor" name="message" rows="6" maxlength="2000000" required></textarea>
      <button class="btn btn-primary" style="margin-top:12px" type="submit">Ответить</button>
    </form>
  <?php else: ?>
    <p style="color:var(--text-dim);font-size:13px;margin:20px 0"><a href="/auth/login.php" style="color:var(--accent-2)">Войдите</a>, чтобы ответить в теме.</p>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
