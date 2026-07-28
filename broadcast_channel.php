<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/service_helpers.php';
$__user = require_login();

$slug = $_GET['slug'] ?? '';
$stmt = db()->prepare('SELECT * FROM broadcast_channels WHERE slug = ?');
$stmt->execute([$slug]);
$channel = $stmt->fetch();
if (!$channel) {
  http_response_code(404);
  echo '<div class="container"><div class="empty-state"><h2>Канал не найден</h2></div></div>';
  exit;
}

$isOwner = (int)$channel['owner_id'] === (int)$__user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';
  if ($action === 'subscribe') {
    db()->prepare('INSERT IGNORE INTO broadcast_subscribers (channel_id, user_id) VALUES (?, ?)')->execute([$channel['id'], $__user['id']]);
  } elseif ($action === 'unsubscribe') {
    db()->prepare('DELETE FROM broadcast_subscribers WHERE channel_id = ? AND user_id = ?')->execute([$channel['id'], $__user['id']]);
  } elseif ($action === 'post' && $isOwner) {
    $body = trim(mb_substr((string)($_POST['body'] ?? ''), 0, 4000));
    if ($body !== '') {
      db()->prepare('INSERT INTO broadcast_posts (channel_id, author_id, body) VALUES (?, ?, ?)')->execute([$channel['id'], $__user['id'], $body]);
    }
  } elseif ($action === 'update_avatar' && $isOwner) {
    $url = trim($_POST['avatar_url'] ?? '');
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL)) {
      db()->prepare('UPDATE broadcast_channels SET avatar_url = ? WHERE id = ?')->execute([$url ?: null, $channel['id']]);
    }
  }
  redirect('/broadcast_channel.php?slug=' . urlencode($slug));
}

$stmt = db()->prepare('SELECT 1 FROM broadcast_subscribers WHERE channel_id = ? AND user_id = ?');
$stmt->execute([$channel['id'], $__user['id']]);
$isSubscribed = (bool)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM broadcast_subscribers WHERE channel_id = ?');
$stmt->execute([$channel['id']]);
$subCount = (int)$stmt->fetchColumn();

// Сначала отмечаем просмотр (уникально на пользователя, как в Telegram — повторные заходы
// счётчик не крутят), и только ПОТОМ считаем views_count — если сделать наоборот, первый же
// просмотр покажет 0 вместо 1, потому что счётчик посчитается до вставки собственной записи.
$idsStmt = db()->prepare('SELECT id FROM broadcast_posts WHERE channel_id = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 100');
$idsStmt->execute([$channel['id']]);
$postIds = $idsStmt->fetchAll(PDO::FETCH_COLUMN);
if ($postIds) {
  $viewStmt = db()->prepare('INSERT IGNORE INTO broadcast_post_views (post_id, user_id) VALUES (?, ?)');
  foreach ($postIds as $pid) { $viewStmt->execute([$pid, $__user['id']]); }
}

$stmt = db()->prepare(
  "SELECT bp.*, u.username, u.is_verified, u.avatar, u.is_banned, u.gravatar_email,
     (SELECT COUNT(*) FROM broadcast_post_views v WHERE v.post_id = bp.id) AS views_count,
     (SELECT COUNT(*) FROM broadcast_post_comments c WHERE c.post_id = bp.id) AS comments_count
   FROM broadcast_posts bp JOIN users u ON u.id = bp.author_id
   WHERE bp.channel_id = ? AND bp.is_deleted = 0 ORDER BY bp.id DESC LIMIT 100"
);
$stmt->execute([$channel['id']]);
$posts = $stmt->fetchAll();

// Реакции — одним запросом на все посты сразу (не по одному на пост, чтобы не плодить N+1
// при списке в 100 постов). BROADCAST_REACTIONS — тот же фиксированный набор, что и в
// broadcast_post_react.php, порядок задаёт порядок кнопок в баре реакций.
const BROADCAST_REACTIONS_PALETTE = ['👍', '👎', '❤️', '🔥', '🎉', '😁', '😢', '🤔'];
$reactionsByPost = [];
if ($posts) {
  $postIdList = array_column($posts, 'id');
  $placeholders = implode(',', array_fill(0, count($postIdList), '?'));
  $rstmt = db()->prepare(
    "SELECT post_id, emoji, COUNT(*) AS cnt, SUM(user_id = ?) AS mine
     FROM broadcast_post_reactions WHERE post_id IN ($placeholders) GROUP BY post_id, emoji"
  );
  $rstmt->execute(array_merge([$__user['id']], $postIdList));
  foreach ($rstmt->fetchAll() as $row) {
    $reactionsByPost[$row['post_id']][$row['emoji']] = ['count' => (int)$row['cnt'], 'mine' => (bool)$row['mine']];
  }
}

// Рисует бар реакций под постом — переиспользуется и для уже отрисованных постов,
// и (через JS-аналог renderReactionBar) для новых, прилетающих по поллингу.
function render_reaction_bar(int $postId, array $reactionsByPost): void {
  $mine = $reactionsByPost[$postId] ?? [];
  echo '<div class="post-reactions" data-post-id="' . $postId . '" style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">';
  foreach (BROADCAST_REACTIONS_PALETTE as $emoji) {
    $info = $mine[$emoji] ?? null;
    $count = $info['count'] ?? 0;
    $isMine = $info['mine'] ?? false;
    if ($count === 0) continue; // показываем только реакции, которые уже кто-то поставил — плюс полный набор в пикере ниже
    echo '<button type="button" class="reaction-pill' . ($isMine ? ' reaction-pill-mine' : '') . '" data-emoji="' . e($emoji) . '">' . e($emoji) . ' ' . $count . '</button>';
  }
  echo '<button type="button" class="reaction-add-toggle" title="Поставить реакцию">➕</button>';
  echo '</div>';
}

$pageTitle = $channel['title'];
$seoDescription = $channel['description'] ?: ('Канал ' . $channel['title'] . ' на ' . SITE_NAME);
$seoImage = $channel['avatar_url'] ?: null;
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="msg-chat-header" style="border:1px solid var(--border);border-radius:var(--radius) var(--radius) 0 0;margin-top:20px">
    <img src="<?= e($channel['avatar_url'] ?: '/assets/img/avatar-placeholder.png') ?>" alt="" onerror="this.style.display='none'">
    <div>
      <b><?= e($channel['title']) ?></b>
      <div style="color:var(--text-dim);font-size:12px"><?= (int)$subCount ?> подписчиков<?php if ($channel['description']): ?> · <?= e($channel['description']) ?><?php endif; ?></div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
      <?php if (!$isOwner): ?>
        <form method="POST"><?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $isSubscribed ? 'unsubscribe' : 'subscribe' ?>">
          <button class="btn <?= $isSubscribed ? 'btn-outline' : 'btn-primary' ?> btn-sm" type="submit"><?= $isSubscribed ? 'Отписаться' : 'Подписаться' ?></button>
        </form>
      <?php else: ?>
        <span style="color:var(--accent-2);font-size:12px;align-self:center">Это ваш канал</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="msg-thread" id="posts-feed" data-channel="<?= (int)$channel['id'] ?>" data-after="<?= $posts ? (int)$posts[0]['id'] : 0 ?>"
       style="border:1px solid var(--border);border-top:0;min-height:300px;max-height:60vh">
    <?php foreach (array_reverse($posts) as $p): ?>
      <div class="msg-bubble-row" data-id="<?= (int)$p['id'] ?>">
        <div class="msg-bubble" style="max-width:80%">
          <div style="margin-bottom:4px"><?= render_user_badge($p, 22) ?></div>
          <?= banned_user_notice($p) ?>
          <?= render_with_stickers($p['body']) ?>
          <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;display:flex;gap:10px;align-items:center">
            <span><?= e($p['created_at']) ?></span>
            <span>👁 <span class="post-views-count"><?= (int)$p['views_count'] ?></span></span>
            <span class="post-comments-toggle" data-post-id="<?= (int)$p['id'] ?>" style="cursor:pointer;color:var(--accent-2)">💬 <span class="post-comments-count"><?= (int)$p['comments_count'] ?></span> комментариев</span>
          </div>
          <div class="post-comments-panel" data-post-id="<?= (int)$p['id'] ?>" style="display:none;margin-top:8px;border-top:1px solid var(--border);padding-top:8px"></div>
          <?php render_reaction_bar((int)$p['id'], $reactionsByPost); ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$posts): ?><p style="color:var(--text-dim);text-align:center">Пока нет постов</p><?php endif; ?>
  </div>

  <?php if ($isOwner): ?>
    <form id="post-form" class="msg-input-row" style="border:1px solid var(--border);border-top:0;border-radius:0 0 var(--radius) var(--radius);margin-bottom:30px;position:relative">
      <button type="button" id="post-sticker-btn" class="btn btn-outline" style="padding:8px 12px">😊</button>
      <div id="post-sticker-panel" style="display:none;position:absolute;bottom:100%;left:0;margin-bottom:8px;width:280px;max-height:320px;overflow-y:auto;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px;z-index:50"></div>
      <input type="text" id="post-input" placeholder="Написать пост от имени канала…" autocomplete="off" maxlength="4000">
      <button type="submit" class="btn btn-primary">Опубликовать</button>
    </form>
  <?php else: ?>
    <div style="border:1px solid var(--border);border-top:0;border-radius:0 0 var(--radius) var(--radius);padding:12px;margin-bottom:30px;color:var(--text-dim);font-size:12.5px;text-align:center">
      Публиковать посты может только владелец канала
    </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var feed = document.getElementById('posts-feed');
  var channelId = feed.dataset.channel;
  var stickerMap = {};

  function scrollToBottom() { feed.scrollTop = feed.scrollHeight; }
  scrollToBottom();

  function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function renderBody(text) {
    var html = esc(text).replace(/\n/g, '<br>');
    Object.keys(stickerMap).forEach(function (code) {
      if (html.indexOf(code) === -1) return;
      var img = '<img src="' + stickerMap[code] + '" alt="' + code + '" style="width:22px;height:22px;vertical-align:middle;object-fit:contain;display:inline-block">';
      html = html.split(code).join(img);
    });
    return html;
  }

  function addPost(p) {
    var row = document.createElement('div');
    row.className = 'msg-bubble-row';
    row.dataset.id = p.id;
    var views = p.views_count || 0;
    var comments = p.comments_count || 0;
    row.innerHTML = '<div class="msg-bubble" style="max-width:80%">' +
      '<b style="color:var(--accent-2);font-size:12px;display:block;margin-bottom:4px">' + esc(p.username) + '</b>' +
      renderBody(p.body) +
      '<div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;display:flex;gap:10px;align-items:center">' +
        '<span>только что</span>' +
        '<span>\u{1F441} <span class="post-views-count">' + views + '</span></span>' +
        '<span class="post-comments-toggle" data-post-id="' + p.id + '" style="cursor:pointer;color:var(--accent-2)">\u{1F4AC} <span class="post-comments-count">' + comments + '</span> \u043a\u043e\u043c\u043c\u0435\u043d\u0442\u0430\u0440\u0438\u0435\u0432</span>' +
      '</div>' +
      '<div class="post-comments-panel" data-post-id="' + p.id + '" style="display:none;margin-top:8px;border-top:1px solid var(--border);padding-top:8px"></div>' +
      '<div class="post-reactions" data-post-id="' + p.id + '" style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px"><button type="button" class="reaction-add-toggle" title="Поставить реакцию">\u2795</button></div>' +
    '</div>';
    feed.appendChild(row);
  }

  // ---- Реакции (как в Телеграме) ----
  var REACTIONS_PALETTE = ['\ud83d\udc4d', '\ud83d\udc4e', '\u2764\ufe0f', '\ud83d\udd25', '\ud83c\udf89', '\ud83d\ude01', '\ud83d\ude22', '\ud83e\udd14'];

  function renderReactionBar(bar, counts, myReaction) {
    var html = '';
    REACTIONS_PALETTE.forEach(function (emoji) {
      var count = (counts && counts[emoji]) || 0;
      if (!count) return;
      var mine = emoji === myReaction;
      html += '<button type="button" class="reaction-pill' + (mine ? ' reaction-pill-mine' : '') + '" data-emoji="' + emoji + '">' + emoji + ' ' + count + '</button>';
    });
    html += '<button type="button" class="reaction-add-toggle" title="Поставить реакцию">\u2795</button>';
    bar.innerHTML = html;
  }

  function sendReaction(postId, emoji, bar) {
    fetch('/broadcast_post_react', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ post_id: postId, emoji: emoji })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data.ok) { alert(data.error || 'Не удалось поставить реакцию'); return; }
      renderReactionBar(bar, data.counts, data.my_reaction);
    });
  }

  feed.addEventListener('click', function (e) {
    var addToggle = e.target.closest('.reaction-add-toggle');
    if (addToggle) {
      var bar = addToggle.closest('.post-reactions');
      var existingPicker = bar.querySelector('.reaction-picker');
      if (existingPicker) { existingPicker.remove(); return; }
      var picker = document.createElement('div');
      picker.className = 'reaction-picker';
      picker.style.cssText = 'display:flex;gap:4px;background:var(--bg-elevated);padding:4px 6px;border-radius:8px';
      REACTIONS_PALETTE.forEach(function (emoji) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = emoji;
        btn.style.cssText = 'font-size:15px;background:none;border:0;cursor:pointer;padding:2px 4px';
        btn.addEventListener('click', function () {
          sendReaction(bar.dataset.postId, emoji, bar);
          picker.remove();
        });
        picker.appendChild(btn);
      });
      bar.appendChild(picker);
      return;
    }
    var pill = e.target.closest('.reaction-pill');
    if (pill) {
      var pillBar = pill.closest('.post-reactions');
      sendReaction(pillBar.dataset.postId, pill.dataset.emoji, pillBar);
    }
  });

  // ---- Комментарии под постом (разворачиваются по клику, как в Телеграме) ----
  var loadedComments = {};

  function escAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;'); }

  // Тот же самый синий бейдж, что уже рисует serverside-функция verify_badge() на форуме
  // (см. includes/functions.php) — раньше здесь был голый символ ✓ вместо него.
  function verifyBadgeHtml(isVerified) {
    return isVerified
      ? ' <span class="verify-badge" title="Подтверждённый аккаунт"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.4 16.7 4.9 12.2l1.8-1.8 2.7 2.7 7.9-7.9 1.8 1.8z"/></svg></span>'
      : '';
  }

  function renderComments(panel, postId, comments) {
    var html = '';
    if (!comments.length) {
      html += '<p style="color:var(--text-dim);font-size:12px;margin:4px 0 8px">Пока нет комментариев — будьте первым.</p>';
    } else {
      comments.forEach(function (c) {
        var avatarSrc = c.avatar || '/assets/img/avatar-placeholder.png';
        var bannedNotice = c.is_banned
          ? '<div style="margin-top:3px;font-size:11px;color:var(--danger);background:rgba(255,71,87,0.12);border:1px solid var(--danger);border-radius:6px;padding:2px 6px;display:inline-block">⛔ Аккаунт заблокирован на платформе</div>'
          : '';
        html += '<div style="margin-bottom:8px;font-size:12.5px;display:flex;gap:6px;align-items:flex-start">' +
          '<img src="' + escAttr(avatarSrc) + '" alt="" style="width:20px;height:20px;border-radius:50%;object-fit:cover;flex-shrink:0' + (c.is_banned ? ';filter:grayscale(1);opacity:.6' : '') + '" onerror="this.style.display=\'none\'">' +
          '<span><b style="color:var(--accent-2)">' + esc(c.username) + '</b>' +
          verifyBadgeHtml(c.is_verified) +
          ': ' + esc(c.body) + bannedNotice + '</span>' +
        '</div>';
      });
    }
    html += '<form class="comment-add-form" data-post-id="' + postId + '" style="display:flex;gap:6px;margin-top:6px">' +
      '<input type="text" class="comment-input" placeholder="Написать комментарий…" maxlength="1000" style="flex:1;padding:6px 8px;font-size:12.5px">' +
      '<button type="submit" class="btn btn-outline btn-sm">Отправить</button>' +
    '</form>';
    panel.innerHTML = html;
  }

  feed.addEventListener('click', function (e) {
    var toggle = e.target.closest('.post-comments-toggle');
    if (!toggle) return;
    var postId = toggle.dataset.postId;
    var panel = feed.querySelector('.post-comments-panel[data-post-id="' + postId + '"]');
    if (!panel) return;
    var opening = panel.style.display === 'none';
    panel.style.display = opening ? 'block' : 'none';
    if (opening && !loadedComments[postId]) {
      panel.innerHTML = '<p style="color:var(--text-dim);font-size:12px">Загрузка…</p>';
      fetch('/broadcast_post_comments?post_id=' + postId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
          loadedComments[postId] = true;
          renderComments(panel, postId, (data.comments || []));
        });
    }
  });

  feed.addEventListener('submit', function (e) {
    var form = e.target.closest('.comment-add-form');
    if (!form) return;
    e.preventDefault();
    var postId = form.dataset.postId;
    var input = form.querySelector('.comment-input');
    var text = input.value.trim();
    if (!text) return;
    input.disabled = true;
    fetch('/broadcast_post_comment_add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ post_id: postId, body: text })
    }).then(function (r) { return r.json(); }).then(function (data) {
      input.disabled = false;
      if (!data.ok) { alert(data.error || 'Не удалось отправить'); return; }
      input.value = '';
      var panel = form.closest('.post-comments-panel');
      var countEl = feed.querySelector('.msg-bubble-row[data-id="' + postId + '"] .post-comments-count');
      if (countEl) countEl.textContent = String(parseInt(countEl.textContent, 10) + 1);
      var newLine = document.createElement('div');
      newLine.style.cssText = 'margin-bottom:8px;font-size:12.5px;display:flex;gap:6px;align-items:flex-start';
      var avatarSrc = data.avatar || '/assets/img/avatar-placeholder.png';
      newLine.innerHTML = '<img src="' + escAttr(avatarSrc) + '" alt="" style="width:20px;height:20px;border-radius:50%;object-fit:cover;flex-shrink:0" onerror="this.style.display=\'none\'">' +
        '<span><b style="color:var(--accent-2)">' + esc(data.username) + '</b>' + verifyBadgeHtml(data.is_verified) + ': ' + esc(text) + '</span>';
      panel.insertBefore(newLine, form);
    }).catch(function () { input.disabled = false; });
  });


  <?php if ($isOwner): ?>
  var form = document.getElementById('post-form');
  var input = document.getElementById('post-input');
  var stickerBtn = document.getElementById('post-sticker-btn');
  var stickerPanel = document.getElementById('post-sticker-panel');
  var stickersLoaded = false;

  stickerBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    var opening = stickerPanel.style.display === 'none';
    stickerPanel.style.display = opening ? 'block' : 'none';
    if (opening && !stickersLoaded) {
      stickerPanel.innerHTML = '<div style="color:var(--text-dim);font-size:12px;padding:8px">Загрузка…</div>';
      fetch('/stickers_available').then(function (r) { return r.json(); }).then(function (data) {
        stickersLoaded = true;
        var packs = (data.packs || []);
        if (!packs.length) {
          stickerPanel.innerHTML = '<div style="color:var(--text-dim);font-size:12px;padding:8px">Стикеров пока нет. Создайте или подпишитесь на пак на странице <a href="/sticker_packs.php" style="color:var(--accent-2)">/sticker_packs.php</a></div>';
          return;
        }
        stickerPanel.innerHTML = '';
        packs.forEach(function (pack) {
          var title = document.createElement('div');
          title.textContent = pack.title;
          title.style.cssText = 'font-size:11px;color:var(--text-dim);margin:6px 0 4px';
          stickerPanel.appendChild(title);
          var grid = document.createElement('div');
          grid.style.cssText = 'display:grid;grid-template-columns:repeat(5,1fr);gap:6px';
          pack.items.forEach(function (item) {
            stickerMap[item.code] = item.image_url;
            var img = document.createElement('img');
            img.src = item.image_url;
            img.title = item.code;
            img.style.cssText = 'width:100%;aspect-ratio:1;object-fit:contain;cursor:pointer;border-radius:6px';
            img.addEventListener('click', function () {
              // Вставляем код на месте курсора — можно комбинировать несколько
              // стикеров с текстом в одном посте, панель не закрываем специально
              var start = input.selectionStart ?? input.value.length;
              var before = input.value.slice(0, start);
              var after = input.value.slice(start);
              var needsSpace = before.length && !/\s$/.test(before);
              var insertion = (needsSpace ? ' ' : '') + item.code + ' ';
              input.value = before + insertion + after;
              var newPos = (before + insertion).length;
              input.focus();
              input.setSelectionRange(newPos, newPos);
            });
            grid.appendChild(img);
          });
          stickerPanel.appendChild(grid);
        });
      });
    }
  });
  document.addEventListener('click', function (e) {
    if (!stickerPanel.contains(e.target) && e.target !== stickerBtn) stickerPanel.style.display = 'none';
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = '';
    fetch('/broadcast_post_send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ channel_id: channelId, body: text })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.ok) { feed.dataset.after = data.id; addPost({ id: data.id, username: data.username, body: text }); scrollToBottom(); }
    });
  });
  <?php endif; ?>

  function poll() {
    fetch('/broadcast_post_poll?channel_id=' + channelId + '&after=' + feed.dataset.after)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        (data.posts || []).forEach(function (p) { addPost(p); feed.dataset.after = p.id; });
        if ((data.posts || []).length) scrollToBottom();
      })
      .catch(function () {})
      .finally(function () { setTimeout(poll, 4000); });
  }
  setTimeout(poll, 4000);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
