<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/contacts.php';
contacts_ensure_schema();
require_once __DIR__ . '/includes/oauth.php';
$__user = require_login();

// Если пришли по ссылке "написать" с чужой страницы — находим/создаём диалог и открываем его
$activeConvId = 0;
if (!empty($_GET['with'])) {
  $withId = (int)$_GET['with'];
  if ($withId && $withId !== (int)$__user['id']) {
    $stmt = db()->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$withId]);
    if ($stmt->fetch()) {
      $activeConvId = get_or_create_conversation((int)$__user['id'], $withId);
    }
  }
} elseif (!empty($_GET['conv'])) {
  $activeConvId = (int)$_GET['conv'];
}

// ---- Список диалогов пользователя, с последним сообщением и счётчиком непрочитанных ----
$stmt = db()->prepare(
  "SELECT c.id AS conv_id,
          CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END AS peer_id,
          (SELECT body FROM messages WHERE conversation_id = c.id AND is_deleted = 0 ORDER BY id DESC LIMIT 1) AS last_body,
          (SELECT created_at FROM messages WHERE conversation_id = c.id AND is_deleted = 0 ORDER BY id DESC LIMIT 1) AS last_at,
          (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND sender_id != ? AND read_at IS NULL AND is_deleted = 0) AS unread
   FROM conversations c
   WHERE c.user_a_id = ? OR c.user_b_id = ?
   ORDER BY c.updated_at DESC"
);
$stmt->execute([$__user['id'], $__user['id'], $__user['id'], $__user['id']]);
$conversations = $stmt->fetchAll();

// Подтягиваем данные собеседников одним запросом
$peerIds = array_column($conversations, 'peer_id');
$peers = [];
if ($peerIds) {
  $in = implode(',', array_fill(0, count($peerIds), '?'));
  $stmt = db()->prepare("SELECT id, username, avatar, gravatar_email, is_banned FROM users WHERE id IN ($in)");
  $stmt->execute($peerIds);
  foreach ($stmt->fetchAll() as $p) { $peers[$p['id']] = $p; }
}

// Если открыли диалог напрямую (?with=), а в списке его почему-то ещё нет (только что создан) — подставим
if ($activeConvId && !in_array($activeConvId, array_column($conversations, 'conv_id'))) {
  $stmt = db()->prepare('SELECT CASE WHEN user_a_id = ? THEN user_b_id ELSE user_a_id END AS peer_id FROM conversations WHERE id = ?');
  $stmt->execute([$__user['id'], $activeConvId]);
  $pid = $stmt->fetchColumn();
  if ($pid && !isset($peers[$pid])) {
    $stmt = db()->prepare('SELECT id, username, avatar, gravatar_email, is_banned FROM users WHERE id = ?');
    $stmt->execute([$pid]);
    if ($row = $stmt->fetch()) { $peers[$pid] = $row; }
  }
  if ($pid) array_unshift($conversations, ['conv_id' => $activeConvId, 'peer_id' => $pid, 'last_body' => null, 'last_at' => null, 'unread' => 0]);
}

// Помечаем сообщения открытого диалога прочитанными
if ($activeConvId) {
  db()->prepare('UPDATE messages SET read_at = NOW() WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL')
    ->execute([$activeConvId, $__user['id']]);
}

$activeMessages = [];
if ($activeConvId) {
  $stmt = db()->prepare('SELECT * FROM messages WHERE conversation_id = ? AND is_deleted = 0 ORDER BY id ASC LIMIT 300');
  $stmt->execute([$activeConvId]);
  $activeMessages = $stmt->fetchAll();
}

// ---- "Возможно, вы знакомы" — сверяем друзей ВК (если вход был через vk и есть access_token) с нашей базой ----
$suggestions = [];
if (!empty($__user['oauth_provider']) && $__user['oauth_provider'] === 'vk' && !empty($__user['oauth_access_token'])) {
  try {
    $client = OAuthClient::find('vk');
    if ($client) {
      $friendIds = $client->fetchVkFriendIds($__user['oauth_access_token']);
      if ($friendIds) {
        $existingPeerIds = array_column($conversations, 'peer_id');
        $in = implode(',', array_fill(0, count($friendIds), '?'));
        $stmt = db()->prepare("SELECT id, username, avatar, gravatar_email FROM users WHERE oauth_provider = 'vk' AND oauth_id IN ($in) AND id != ? LIMIT 12");
        $stmt->execute(array_merge($friendIds, [$__user['id']]));
        foreach ($stmt->fetchAll() as $row) {
          if (!in_array($row['id'], $existingPeerIds)) $suggestions[] = $row;
        }
      }
    }
  } catch (\Throwable $e) {
    // ВК может быть недоступен/токен протух — просто не показываем блок, не ломаем страницу
  }
}

$pageTitle = 'Сообщения';

// Состояние контактов/блока для активного диалога
$peerContact = false;
$peerMutual = false;
$peerBlockedByMe = false;
$peerBlockedMe = false;
$peerIsStranger = true;
$activePeerId = 0;
if ($activeConvId) {
  foreach ($conversations as $c) {
    if ((int)$c['conv_id'] === (int)$activeConvId) {
      $activePeerId = (int)$c['peer_id'];
      break;
    }
  }
}
if ($activePeerId > 0) {
  $peerContact = contacts_is_contact((int)$__user['id'], $activePeerId);
  $peerMutual = contacts_are_mutual((int)$__user['id'], $activePeerId);
  $peerBlockedByMe = contacts_is_blocked((int)$__user['id'], $activePeerId);
  $peerBlockedMe = contacts_is_blocked($activePeerId, (int)$__user['id']);
  $peerIsStranger = !$peerContact;
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="msg-shell">
    <aside class="msg-sidebar">
      <h2 style="margin:0 0 14px">Сообщения</h2>
      <?= rating_place_banner((int)$__user['id']) ?>

      <div class="msg-search-box">
        <input type="text" id="msg-user-search" placeholder="Найти человека по нику…" autocomplete="off">
        <div id="msg-search-results" class="msg-search-results" style="display:none"></div>
      </div>

      <?php
        $__myBcChannels = [];
        try {
          $stmt = db()->prepare(
            "SELECT bc.slug, bc.title, bc.avatar_url FROM broadcast_channels bc
             JOIN broadcast_subscribers bs ON bs.channel_id = bc.id
             WHERE bs.user_id = ? ORDER BY bc.title LIMIT 10"
          );
          $stmt->execute([$__user['id']]);
          $__myBcChannels = $stmt->fetchAll();
        } catch (\Throwable $e) { /* миграция ещё не накатана — просто не показываем блок */ }
      ?>
      <?php if ($__myBcChannels): ?>
        <h3 style="margin:18px 0 8px;font-size:13px;display:flex;justify-content:space-between;align-items:center">
          📢 Каналы <a href="/broadcast_channels.php" style="font-size:11px;color:var(--accent-2)">все →</a>
        </h3>
        <div class="msg-conv-list" style="margin-top:0">
          <?php foreach ($__myBcChannels as $bc): ?>
            <a href="/broadcast_channel.php?slug=<?= e($bc['slug']) ?>" class="msg-conv-item">
              <img src="<?= e($bc['avatar_url'] ?: '/assets/img/avatar-placeholder.png') ?>" alt="" onerror="this.style.display='none'">
              <div class="msg-conv-meta"><b><?= e($bc['title']) ?></b></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <a href="/broadcast_channels.php" style="display:block;margin:14px 0;font-size:12.5px;color:var(--accent-2)">📢 Смотреть/создать каналы →</a>
      <?php endif; ?>

      <?php if (!$conversations): ?>
        <p style="color:var(--text-dim);font-size:13px;margin-top:14px">Пока нет диалогов. Найдите человека выше или начните переписку со страницы форума.</p>
      <?php endif; ?>
      <div class="msg-conv-list">
        <?php foreach ($conversations as $c): $peer = $peers[$c['peer_id']] ?? null; if (!$peer) continue; ?>
          <a href="/messages.php?conv=<?= (int)$c['conv_id'] ?>" class="msg-conv-item <?= $activeConvId === (int)$c['conv_id'] ? 'active' : '' ?>">
            <img src="<?= e(user_avatar_url($peer, 48)) ?>" alt="" onerror="this.style.display='none'">
            <div class="msg-conv-meta">
              <b><?= e($peer['username']) ?></b>
              <span><?= e(mb_strimwidth((string)($c['last_body'] ?? 'Начните переписку'), 0, 34, '…')) ?></span>
            </div>
            <?php if ($c['unread'] > 0): ?><span class="msg-unread-badge"><?= (int)$c['unread'] ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($suggestions): ?>
        <h3 style="margin:22px 0 10px;font-size:14px">Возможно, вы знакомы</h3>
        <div class="msg-suggest-list">
          <?php foreach ($suggestions as $s): ?>
            <a href="/messages.php?with=<?= (int)$s['id'] ?>" class="msg-suggest-item">
              <img src="<?= e(user_avatar_url($s, 48)) ?>" alt="" onerror="this.style.display='none'">
              <span><?= e($s['username']) ?></span>
              <b>Написать</b>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </aside>

    <section class="msg-chat">
      <?php if (!$activeConvId): ?>
        <div class="msg-empty">
          <p>Выберите диалог слева или начните новый со страницы пользователя.</p>
        </div>
      <?php else: $peer = $peers[$conversations[array_search($activeConvId, array_column($conversations, 'conv_id'))]['peer_id']] ?? null; ?>
        <div class="msg-chat-header" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between">
          <div style="display:flex;align-items:center;gap:10px;min-width:0">
          <?php if ($peer): ?>
            <a href="/profile.php?username=<?= e(urlencode($peer['username'])) ?>" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;min-width:0">
              <img src="<?= e(user_avatar_url($peer, 48)) ?>" alt="" onerror="this.style.display='none'" style="width:40px;height:40px;border-radius:50%;object-fit:cover;<?= !empty($peer['is_banned']) ? 'filter:grayscale(1);opacity:.6' : '' ?>">
              <div style="min-width:0">
                <b style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($peer['username']) ?></b>
                <?php if ($peerMutual): ?>
                  <span style="font-size:11px;color:var(--ok,#2ecc71)">в контактах · взаимно</span>
                <?php elseif ($peerContact): ?>
                  <span style="font-size:11px;color:var(--text-dim)">в ваших контактах</span>
                <?php elseif ($peerIsStranger): ?>
                  <span style="font-size:11px;color:var(--text-dim)">незнакомец</span>
                <?php endif; ?>
              </div>
            </a>
          <?php endif; ?>
          </div>
          <?php if ($peer && $activePeerId): ?>
          <div class="msg-peer-actions" style="display:flex;flex-wrap:wrap;gap:6px">
            <?php if ($peerBlockedByMe): ?>
              <button type="button" class="btn btn-outline btn-sm" id="msg-unblock-btn" data-uid="<?= (int)$activePeerId ?>">Разблокировать</button>
            <?php else: ?>
              <?php if ($peerContact): ?>
                <button type="button" class="btn btn-outline btn-sm" id="msg-contact-btn" data-uid="<?= (int)$activePeerId ?>" data-act="remove">Удалить из контактов</button>
              <?php else: ?>
                <button type="button" class="btn btn-primary btn-sm" id="msg-contact-btn" data-uid="<?= (int)$activePeerId ?>" data-act="add">В контакты</button>
              <?php endif; ?>
              <button type="button" class="btn btn-danger btn-sm" id="msg-block-btn" data-uid="<?= (int)$activePeerId ?>">Заблокировать</button>
              <button type="button" class="btn btn-outline btn-sm" id="msg-spam-btn" data-uid="<?= (int)$activePeerId ?>" title="Пожаловаться на спам">Спам</button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($peer && !empty($peer['is_banned'])): ?>
            <div style="flex-basis:100%;margin-top:3px;font-size:11.5px;color:var(--danger);background:rgba(255,71,87,0.12);border:1px solid var(--danger);border-radius:6px;padding:2px 8px;display:inline-block">⛔ Этот аккаунт заблокирован на платформе. Мы не несём ответственности за действия пользователя вне платформы.</div>
          <?php endif; ?>
          <?php if ($peerBlockedMe && !$peerBlockedByMe): ?>
            <div style="flex-basis:100%;font-size:12px;color:var(--text-dim)">Пользователь ограничил переписку с вами.</div>
          <?php endif; ?>
          <?php if ($peerBlockedByMe): ?>
            <div style="flex-basis:100%;font-size:12px;color:var(--text-dim)">Вы заблокировали этого пользователя. Сообщения недоступны.</div>
          <?php endif; ?>
        </div>
        <div class="msg-thread" id="msg-thread" data-conv="<?= (int)$activeConvId ?>" data-my-id="<?= (int)$__user['id'] ?>" data-after="<?= $activeMessages ? (int)end($activeMessages)['id'] : 0 ?>">
          <?php foreach ($activeMessages as $m): ?>
            <div class="msg-bubble-row <?= (int)$m['sender_id'] === (int)$__user['id'] ? 'own' : '' ?>" data-id="<?= (int)$m['id'] ?>">
              <div class="msg-bubble"><?= render_with_stickers($m['body']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($peerBlockedByMe || $peerBlockedMe): ?>
          <div class="msg-input-row" style="opacity:.7;padding:12px;color:var(--text-dim);font-size:13px">Переписка недоступна из‑за блокировки.</div>
        <?php else: ?>
        <form id="msg-form" class="msg-input-row" style="position:relative">
          <button type="button" id="msg-sticker-btn" class="btn btn-outline" style="padding:8px 12px">😊</button>
          <div id="msg-sticker-panel" style="display:none;position:absolute;bottom:100%;left:0;margin-bottom:8px;width:280px;max-height:320px;overflow-y:auto;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px;z-index:50"></div>
          <input type="text" id="msg-input" placeholder="Написать сообщение…" autocomplete="off" maxlength="2000">
          <button type="submit" class="btn btn-primary">Отправить</button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>

<script>
(function () {
  var input = document.getElementById('msg-user-search');
  var results = document.getElementById('msg-search-results');
  var timer = null;

  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function render(users) {
    if (!users.length) {
      results.innerHTML = '<div class="msg-search-empty">Никого не нашли</div>';
      results.style.display = '';
      return;
    }
    results.innerHTML = users.map(function (u) {
      var avatar = u.avatar ? escapeHtml(u.avatar) : '/assets/img/avatar-placeholder.png';
      return '<a href="/messages.php?with=' + u.id + '" class="msg-search-item">' +
        '<img src="' + avatar + '" alt="" onerror="this.style.display=\'none\'">' +
        '<span>' + escapeHtml(u.username) + '</span></a>';
    }).join('');
    results.style.display = '';
  }

  input.addEventListener('input', function () {
    var q = input.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { results.style.display = 'none'; return; }
    timer = setTimeout(function () {
      fetch('/user_search?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (data) { render(data.users || []); })
        .catch(function () {});
    }, 250);
  });

  document.addEventListener('click', function (e) {
    if (!results.contains(e.target) && e.target !== input) results.style.display = 'none';
  });
})();
</script>

<?php if ($activeConvId): ?>
<script>
(function () {
  var thread = document.getElementById('msg-thread');
  var convId = thread.dataset.conv;
  var myId = thread.dataset.myId;
  var form = document.getElementById('msg-form');
  var input = document.getElementById('msg-input');
  var stickerBtn = document.getElementById('msg-sticker-btn');
  var stickerPanel = document.getElementById('msg-sticker-panel');
  var stickerMap = {}; // code -> image_url, чтобы рендерить стикеры в новых сообщениях без перезагрузки страницы

  function scrollToBottom() { thread.scrollTop = thread.scrollHeight; }
  scrollToBottom();

  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // Заменяет известные :code: на <img>, аналогично серверной render_with_stickers()
  function renderBody(text) {
    var html = escapeHtml(text).replace(/\n/g, '<br>');
    Object.keys(stickerMap).forEach(function (code) {
      if (html.indexOf(code) === -1) return;
      var img = '<img src="' + stickerMap[code] + '" alt="' + code + '" class="inline-sticker" style="width:22px;height:22px;vertical-align:middle;object-fit:contain;display:inline-block">';
      html = html.split(code).join(img);
    });
    return html;
  }

  function addBubble(m) {
    var row = document.createElement('div');
    row.className = 'msg-bubble-row' + (String(m.senderId) === String(myId) ? ' own' : '');
    row.dataset.id = m.id;
    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    bubble.innerHTML = renderBody(m.body);
    row.appendChild(bubble);
    thread.appendChild(row);
  }

  function sendMessage(text) {
    if (!text) return;
    fetch('/message_send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ conversation_id: convId, body: text })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.ok) {
        thread.dataset.after = data.id;
        addBubble({ id: data.id, senderId: myId, body: text });
        scrollToBottom();
      }
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    input.value = '';
    sendMessage(text);
  });

  // Стикер-пикер: подгружаем один раз при первом открытии (свои паки + подписки)
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
              // Вставляем код стикера в позицию курсора — можно комбинировать
              // несколько стикеров с текстом в одном сообщении (стикер, текст, снова
              // стикер...), панель специально не закрываем, чтобы удобно было добавить ещё
              var start = input.selectionStart ?? input.value.length;
              var end = input.selectionEnd ?? input.value.length;
              var before = input.value.slice(0, start);
              var after = input.value.slice(start);
              var needsSpaceBefore = before.length && !/\s$/.test(before);
              var insertion = (needsSpaceBefore ? ' ' : '') + item.code + ' ';
              input.value = before + insertion + after;
              var newPos = (before + insertion).length;
              input.focus();
              input.setSelectionRange(newPos, newPos);
            });
            grid.appendChild(img);
          });
          stickerPanel.appendChild(grid);
        });
      }).catch(function () {
        stickerPanel.innerHTML = '<div style="color:var(--danger);font-size:12px;padding:8px">Не удалось загрузить стикеры</div>';
      });
    }
  });
  document.addEventListener('click', function (e) {
    if (!stickerPanel.contains(e.target) && e.target !== stickerBtn) stickerPanel.style.display = 'none';
  });

  function poll() {
    fetch('/message_poll?conversation_id=' + convId + '&after=' + thread.dataset.after)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        (data.messages || []).forEach(function (m) {
          addBubble(m);
          thread.dataset.after = m.id;
        });
        if ((data.messages || []).length) scrollToBottom();
      })
      .catch(function () {})
      .finally(function () { setTimeout(poll, 3000); });
  }
  setTimeout(poll, 3000);
})();
</script>
<?php endif; ?>

<script>
(function () {
  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin'
    }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
  }
  function bind(id, fn) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', fn);
  }
  bind('msg-contact-btn', function () {
    var btn = this;
    var uid = parseInt(btn.getAttribute('data-uid'), 10);
    var act = btn.getAttribute('data-act') || 'add';
    btn.disabled = true;
    postJson('/contact_action.php', { action: act, user_id: uid }).then(function (d) {
      if (d && d.ok) location.reload();
      else { alert((d && d.error) || 'Не удалось'); btn.disabled = false; }
    });
  });
  bind('msg-block-btn', function () {
    if (!confirm('Заблокировать пользователя? Он не сможет писать вам, и вы не увидите его сообщения.')) return;
    var btn = this;
    var uid = parseInt(btn.getAttribute('data-uid'), 10);
    btn.disabled = true;
    postJson('/user_block_action.php', { action: 'block', user_id: uid }).then(function (d) {
      if (d && d.ok) location.reload();
      else { alert((d && d.error) || 'Не удалось'); btn.disabled = false; }
    });
  });
  bind('msg-unblock-btn', function () {
    var btn = this;
    var uid = parseInt(btn.getAttribute('data-uid'), 10);
    btn.disabled = true;
    postJson('/user_block_action.php', { action: 'unblock', user_id: uid }).then(function (d) {
      if (d && d.ok) location.reload();
      else { alert((d && d.error) || 'Не удалось'); btn.disabled = false; }
    });
  });
  bind('msg-spam-btn', function () {
    if (!confirm('Пожаловаться на спам? После 10 жалоб от разных людей аккаунт может быть заблокирован на платформе.')) return;
    var btn = this;
    var uid = parseInt(btn.getAttribute('data-uid'), 10);
    btn.disabled = true;
    postJson('/user_report_action.php', { user_id: uid, reason: 'spam', severity: 'medium' }).then(function (d) {
      alert((d && (d.message || d.error)) || (d && d.ok ? 'Жалоба принята' : 'Ошибка'));
      btn.disabled = false;
    });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
