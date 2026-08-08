<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/user_display.php';
user_display_ensure_schema();
try { user_display_ensure_schema(); } catch (Throwable $e) {}

require_once __DIR__ . '/includes/service_helpers.php';
require_once __DIR__ . '/includes/bbcode.php';
if (is_file(__DIR__ . '/includes/share.php')) require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/forum_engine.php';
forum_engine_ensure();
if (is_file(__DIR__ . '/includes/content_moderation.php')) {
  require_once __DIR__ . '/includes/content_moderation.php';
  try { cmod_ensure_schema(); } catch (Throwable $e) {}
}
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
  $extraHead = ($extraHead ?? '') . '<link rel="stylesheet" href="/assets/css/user-display.css?v=20260801everywhere';
require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Тема не найдена</h2><a href="/forum.php" class="btn btn-primary" style="margin-top:14px">На форум</a></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}


$__ms = (string)($thread['mod_status'] ?? 'approved');
if ($__ms === 'pending' || $__ms === 'rejected') {
  $__uid = (int)($__user['id'] ?? 0);
  $__staff = $__user && (
    in_array($__user['role'] ?? '', ['admin','moderator'], true)
    || (function_exists('is_forum_moderator') && is_forum_moderator($__user))
    || (function_exists('cmod_is_moderator') && cmod_is_moderator($__user))
  );
  $__owner = $__uid > 0 && $__uid === (int)$thread['user_id'];
  if (!$__staff && !$__owner) {
    http_response_code(403);
    $pageTitle = 'Тема на модерации';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="container"><div class="empty-state"><h2>Тема на модерации</h2><p>После одобрения её увидят все.</p><a class="btn btn-primary" href="/forum.php">На форум</a></div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
  }
}
$isForumModerator = is_forum_moderator($__user);

// ---- Обработка форм (ответ, удаление сообщения, пин/лок) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? 'reply';

  if ($action === 'reply' && $__user && !$thread['is_locked']) {
    $message = trim(mb_substr($_POST['message'] ?? '', 0, 2000000));
    if ($message !== '') {
      $__postMod = 'pending';
      if (($__user['role'] ?? '') === 'admin') $__postMod = 'approved';
      try {
        db()->prepare('INSERT INTO forum_posts (thread_id, user_id, message, mod_status) VALUES (?, ?, ?, ?)')
          ->execute([$threadId, $__user['id'], $message, $__postMod]);
      } catch (Throwable $e) {
        db()->prepare('INSERT INTO forum_posts (thread_id, user_id, message) VALUES (?, ?, ?)')
          ->execute([$threadId, $__user['id'], $message]);
      }
      $postId = (int)db()->lastInsertId();
      $__queued = false;
      try {
        if ($postId > 0 && function_exists('cmod_enqueue')) {
          cmod_enqueue('post', $postId, (int)$__user['id'], mb_substr($message, 0, 80), mb_substr($message, 0, 300));
          $__queued = true;
        } elseif ($postId > 0) {
          db()->prepare("UPDATE forum_posts SET mod_status='pending' WHERE id=?")->execute([$postId]);
          $__queued = true;
        }
      } catch (Throwable $e) {
        try { if ($postId > 0) db()->prepare("UPDATE forum_posts SET mod_status='pending' WHERE id=?")->execute([$postId]); $__queued = true; } catch (Throwable $e2) {}
      }
      if ($__queued) {
        if (function_exists('flash_set')) flash_set('success', 'Сообщение на модерации. Пока его видите только вы.');
      } else {
        forum_bump_reply_stats($threadId, (int)$__user['id']);
      }
      try {
        $authorId = (int)($thread['user_id'] ?? 0);
        $tTitle = mb_substr((string)($thread['title'] ?? 'тема'), 0, 60);
        $link = '/forum_thread?id=' . (int)$threadId;
        $msg = '@' . ($__user['username'] ?? 'user') . ' ответил(а) в «' . $tTitle . '»';
        if (function_exists('notify_user')) notify_user($authorId, 'forum_reply', $msg, $link, null, (int)$__user['id']);
        try {
          $ws = db()->prepare('SELECT user_id FROM forum_thread_watch WHERE thread_id = ?');
          $ws->execute([(int)$threadId]);
          $ids = [];
          while ($w = $ws->fetch()) $ids[] = (int)$w['user_id'];
          if (function_exists('notify_users')) notify_users($ids, 'forum_reply', $msg, $link, null, (int)$__user['id']);
        } catch (Throwable $e) {}
      } catch (Throwable $e) {}
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

try {
  $stmt = db()->prepare(
    'SELECT fp.*, u.username, u.role, u.avatar, u.is_verified, u.is_banned, u.gravatar_email,
            u.id AS id, u.prefix_id, u.username_css, u.profile_cover, u.profile_status, u.session_started_at, u.last_seen_at, u.session_seconds
     FROM forum_posts fp
     JOIN users u ON u.id = fp.user_id
     WHERE fp.thread_id = ? AND fp.is_deleted = 0 ORDER BY fp.created_at ASC LIMIT 500'
  );
  $stmt->execute([$threadId]);
  $posts = $stmt->fetchAll() ?: [];
  $__uid = (int)($__user['id'] ?? 0);
  $__staff = $__user && (in_array($__user['role'] ?? '', ['admin','moderator'], true) || (function_exists('is_forum_moderator') && is_forum_moderator($__user)));
  $__uid = (int)($__user['id'] ?? 0);
  $__staff = $__user && (
    in_array($__user['role'] ?? '', ['admin', 'moderator'], true)
    || (function_exists('is_forum_moderator') && is_forum_moderator($__user))
    || (function_exists('cmod_is_moderator') && cmod_is_moderator($__user))
  );
  $posts = array_values(array_filter($posts ?: [], function ($fp) use ($__uid, $__staff) {
    if (!empty($fp['is_deleted'])) return false;
    $ms = strtolower(trim((string)($fp['mod_status'] ?? 'approved')));
    // Публично только approved. pending/rejected — автор и staff.
    if ($ms === 'approved') return true;
    if ($ms === '' || $ms === '0') return true; // старые посты без колонки
    if ($__staff) return true;
    return $__uid > 0 && (int)($fp['user_id'] ?? 0) === $__uid;
  }));
} catch (Throwable $e) {
  $stmt = db()->prepare(
    'SELECT fp.*, u.username, u.role, u.avatar, u.is_verified, u.is_banned, u.gravatar_email,
            u.username_css, u.prefix_id, u.custom_prefix_id, u.profile_cover_url, u.profile_status_text,
            u.nick_decor_url, u.nick_decor_pos, u.id AS id
     FROM forum_posts fp JOIN users u ON u.id = fp.user_id
     WHERE fp.thread_id = ? AND fp.is_deleted = 0 ORDER BY fp.created_at ASC LIMIT 500'
  );
  $stmt->execute([$threadId]);
  $posts = $stmt->fetchAll();
}

$postIds = array_map(static function ($row) { return (int)$row['id']; }, $posts);
$likedIds = ($__user && $postIds) ? forum_user_liked_posts((int)$__user['id'], $postIds) : [];
$watching = $__user ? forum_thread_is_watching($threadId, (int)$__user['id']) : false;
if ($__user && $postIds) {
  forum_mark_read($threadId, (int)$__user['id'], max($postIds));
}
// актуальные счётчики лайков (batch)
$likeCounts = [];
if ($postIds) {
  try {
    $in = implode(',', array_map('intval', $postIds));
    $st = db()->query("SELECT post_id, COUNT(*) AS c FROM forum_post_likes WHERE post_id IN ($in) GROUP BY post_id");
    while ($row = $st->fetch()) {
      $likeCounts[(int)$row['post_id']] = (int)$row['c'];
    }
  } catch (Throwable $e) {
    foreach ($postIds as $pid) {
      $likeCounts[$pid] = function_exists('forum_post_like_count') ? forum_post_like_count((int)$pid) : 0;
    }
  }
  foreach ($postIds as $pid) {
    if (!isset($likeCounts[$pid])) $likeCounts[$pid] = 0;
  }
}

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
    <?php if ($__user): ?>
      <form method="POST" action="/forum_action" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="watch">
        <input type="hidden" name="thread_id" value="<?= (int)$threadId ?>">
        <button class="btn btn-outline btn-sm" type="submit"><?= !empty($watching) ? '★ Следите' : '☆ Следить' ?></button>
      </form>
    <?php endif; ?>
    <?php if ($isForumModerator): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_pin"><button class="btn btn-outline btn-sm" type="submit"><?= $thread['is_pinned'] ? 'Открепить' : 'Закрепить' ?></button></form>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_lock"><button class="btn btn-outline btn-sm" type="submit"><?= $thread['is_locked'] ? 'Открыть' : 'Закрыть' ?></button></form>
        <form method="POST" onsubmit="return confirm('Удалить тему целиком?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_thread"><button class="btn btn-danger btn-sm" type="submit">Удалить тему</button></form>
      </div>
    <?php endif; ?>
  </div>

  <?php if (function_exists('share_buttons')): echo share_buttons('/forum_thread.php?id=' . (int)$threadId, (string)($thread['title'] ?? 'Тема')); endif; ?>
  <div id="posts" class="forum-post-list">
    <?php foreach ($posts as $p): ?>
      <div class="forum-post" id="post-<?= (int)$p['id'] ?>">
        <div class="forum-post-author">
          <?php
            if (empty($p['id']) && !empty($p['user_id'])) $p['id'] = (int)$p['user_id'];
            if (function_exists('user_display_ensure_schema')) user_display_ensure_schema();
            echo render_user_badge($p, 28);
          ?>
          <?php if ($p['role'] === 'admin'): ?><span class="role-badge">админ</span><?php endif; ?>
          <span style="color:var(--text-dim);font-size:12px"><?= e($p['created_at']) ?></span>
          <?php if ($__user && (int)$p['user_id'] !== (int)$__user['id']): ?>
            <a href="/messages?with=<?= (int)$p['user_id'] ?>" style="color:var(--accent-2);font-size:12px;margin-left:4px">написать</a>
          <?php endif; ?>
        </div>
        <?= banned_user_notice($p) ?>
        <div class="forum-post-body"><?= bbcode_to_html($p['message'], (int)$p['id']) ?></div>
        <div class="forum-post-actions" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;align-items:center">
          <?php if ($__user): ?>
            <?php
              $__pid = (int)$p['id'];
              $__liked = in_array($__pid, $likedIds ?? [], true);
              $__lc = (int)($likeCounts[$__pid] ?? 0); // только COUNT из forum_post_likes
            ?>
            <form method="POST" action="/forum_action" style="display:inline" class="js-forum-like-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="like">
              <input type="hidden" name="post_id" value="<?= $__pid ?>">
              <input type="hidden" name="redirect" value="/forum_thread.php?id=<?= (int)$threadId ?>#post-<?= $__pid ?>">
              <button type="submit" class="btn btn-outline btn-sm js-forum-like-btn" data-post-id="<?= $__pid ?>">
                <?= $__liked ? '♥' : '♡' ?> <?= $__lc ?>
              </button>
            </form>
            <button type="button" class="btn btn-outline btn-sm js-forum-quote"
              data-post-id="<?= (int)$__pid ?>"
              data-username="<?= e($p['username'] ?? '') ?>"
              data-raw-b64="<?= e(base64_encode(mb_substr(preg_replace('/\s+/u', ' ', (string)($p['message'] ?? '')), 0, 1200))) ?>"
            >Цитировать</button>
            <form method="POST" action="/forum_action" style="display:inline" onsubmit="return confirm('Пожаловаться на сообщение?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="report">
              <input type="hidden" name="post_id" value="<?= $__pid ?>">
              <input type="hidden" name="reason" value="spam">
              <input type="hidden" name="redirect" value="/forum_thread.php?id=<?= (int)$threadId ?>#post-<?= $__pid ?>">
              <button class="btn btn-outline btn-sm" type="submit">Жалоба</button>
            </form>
            <?php if ((int)$p['user_id'] === (int)$__user['id'] || $isForumModerator): ?>
              <details>
                <summary class="btn btn-outline btn-sm" style="cursor:pointer;list-style:none">Изменить</summary>
                <form method="POST" action="/forum_action" style="margin-top:8px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="edit">
                  <input type="hidden" name="post_id" value="<?= $__pid ?>">
                  <textarea name="message" rows="4" style="width:100%;min-width:240px"><?= e($p['message']) ?></textarea>
                  <button class="btn btn-primary btn-sm" type="submit">Сохранить</button>
                </form>
              </details>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($p['updated_at'])): ?>
            <span style="font-size:11px;color:var(--text-dim)">изм. <?= e($p['updated_at']) ?></span>
          <?php endif; ?>
        </div>
        <div class="forum-post-footer-mini"><?php $p['id'] = (int)($p['id'] ?? $p['user_id'] ?? 0); echo user_render_mini_profile($p); ?></div>
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

<script>
(function () {
  function forumQuoteFromBtn(btn) {
    var ta = document.getElementById('bb-editor') || document.querySelector('textarea[name="message"]');
    if (!ta) { alert('Войдите и откройте форму ответа внизу, чтобы цитировать.'); return; }
    var username = (btn.getAttribute('data-username') || 'user').replace(/"/g, '');
    var text = '';
    var b64 = btn.getAttribute('data-raw-b64') || '';
    if (b64) {
      try {
        text = decodeURIComponent(escape(atob(b64)));
      } catch (err) {
        try { text = atob(b64); } catch (e2) { text = ''; }
      }
    }
    if (!text) text = btn.getAttribute('data-raw') || '';
    if (!text) {
      var id = btn.getAttribute('data-post-id');
      var el = document.getElementById('post-' + id);
      if (el) {
        var body = el.querySelector('.forum-post-body');
        text = ((body && body.innerText) || '').trim().slice(0, 1200);
      }
    }
    text = (text || '').replace(/\[\/?quote[^\]]*\]/gi, '').trim();
    var block = '[quote="' + username + '"]' + text + '[/quote]\n';
    ta.value = (ta.value ? ta.value.replace(/\s+$/, '') + '\n\n' : '') + block;
    ta.focus();
    try { ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
  }
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest && e.target.closest('.js-forum-quote');
    if (!btn) return;
    e.preventDefault();
    forumQuoteFromBtn(btn);
  });
})();
document.addEventListener('submit', function (e) {
  var form = e.target;
  if (!form || !form.classList || !form.classList.contains('js-forum-like-form')) return;
  e.preventDefault();
  var btn = form.querySelector('.js-forum-like-btn');
  var fd = new FormData(form);
  fd.set('ajax', '1');
  fetch('/forum_action', { method: 'POST', body: fd, credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
  }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d || !d.ok) { form.classList.remove('js-forum-like-form'); form.submit(); return; }
    if (btn) btn.textContent = (d.liked ? '♥ ' : '♡ ') + d.count;
  }).catch(function () { form.classList.remove('js-forum-like-form'); form.submit(); });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
