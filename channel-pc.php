<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
$stmt = db()->prepare('SELECT * FROM channels WHERE slug = ?');
$stmt->execute([$slug]);
$channel = $stmt->fetch();

require_once __DIR__ . '/includes/auth.php';
$__user = current_user();

if (!$channel) {
  http_response_code(404);
  $pageTitle = 'Канал не найден';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Канал не найден</h2><p>Такого канала не существует</p><a href="/catalog.php" class="btn btn-primary" style="margin-top:14px">В каталог</a></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

if ($channel['status'] !== 'approved') {
  http_response_code(403);
  $pageTitle = 'Канал недоступен';
  require_once __DIR__ . '/includes/header.php';
  $msg = $channel['status'] === 'pending'
    ? 'Канал ещё проходит модерацию и пока не допущен в каталог'
    : 'Канал не был допущен в каталог' . ($channel['reject_reason'] ? ': ' . e($channel['reject_reason']) : '');
  echo '<div class="container"><div class="empty-state"><h2>Канал недоступен</h2><p>' . $msg . '</p><a href="/catalog.php" class="btn btn-primary" style="margin-top:14px">В каталог</a></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

if ($__user && is_user_banned_on_channel($channel['id'], $__user['id'])) {
  http_response_code(403);
  $pageTitle = 'Доступ закрыт';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>УВЫ, ВАС ЗАБЛОКИРОВАЛИ</h2><p>Модератор этого канала закрыл вам доступ к просмотру и чату.</p><a href="/catalog.php" class="btn btn-primary" style="margin-top:14px">В каталог</a></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

db()->prepare('UPDATE channels SET views = views + 1 WHERE id = ?')->execute([$channel['id']]);

$activeSource = resolve_active_source($channel);
$nextProgram = find_next_program($channel['id']);

$stmt = db()->prepare('SELECT * FROM stickers WHERE channel_id = ?');
$stmt->execute([$channel['id']]);
$stickers = $stmt->fetchAll();

$stmt = db()->prepare('SELECT sch.*, s.name as source_name FROM schedule sch JOIN sources s ON s.id = sch.source_id WHERE channel_id = ? ORDER BY day_of_week, start_time');
$stmt->execute([$channel['id']]);
$schedule = $stmt->fetchAll();

$stmt = db()->prepare(
  'SELECT cm.id, cm.message, cm.created_at, u.username, u.is_verified FROM comments cm JOIN users u ON u.id = cm.user_id
   WHERE cm.channel_id = ? AND cm.is_deleted = 0 ORDER BY cm.created_at DESC LIMIT 100'
);
$stmt->execute([$channel['id']]);
$comments = $stmt->fetchAll();

$pageTitle = $channel['seo_title'] ?: $channel['title'];
$seoDescription = $channel['seo_description'] ?: $channel['description'];
$seoKeywords = $channel['seo_keywords'] ?: '';
$seoImage = $channel['logo_url'] ?: null;
$extraHead = '<style>.player-wrap{position:relative;background:#000;display:flex;align-items:center;justify-content:center;min-height:360px;width:100%}.player-wrap #playerjs-container{width:100%;height:100%;min-height:360px}.player-wrap .player-logo{position:absolute;top:16px;right:16px;height:48px;width:auto;z-index:10;pointer-events:none;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.3)}</style>';
require_once __DIR__ . '/includes/header.php';

// Структурированные данные (schema.org) — помогает поисковикам понять, что это
// страница теле/радио-канала, и может дать красивый сниппет в выдаче Google/Яндекс.
$__jsonLd = [
  '@context' => 'https://schema.org',
  '@type' => $channel['type'] === 'radio' ? 'RadioChannel' : 'TelevisionChannel',
  'name' => $channel['title'],
  'description' => $channel['description'],
  'url' => SITE_URL . '/channel-pc.php?slug=' . urlencode($channel['slug']),
];
if ($channel['logo_url']) $__jsonLd['image'] = $channel['logo_url'];
echo '<script type="application/ld+json">' . json_encode($__jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

$isModerator = $__user && ($__user['id'] === (int)$channel['owner_id'] || $__user['role'] === 'admin');
$days = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

$stmt = db()->prepare('SELECT COUNT(*) FROM channel_likes WHERE channel_id = ?');
$stmt->execute([$channel['id']]);
$likeCount = (int)$stmt->fetchColumn();
$liked = false;
$favorited = false;
if ($__user) {
  $stmt = db()->prepare('SELECT id FROM channel_likes WHERE channel_id = ? AND user_id = ?');
  $stmt->execute([$channel['id'], $__user['id']]);
  $liked = (bool)$stmt->fetch();
  $stmt = db()->prepare('SELECT id FROM favorites WHERE channel_id = ? AND user_id = ?');
  $stmt->execute([$channel['id'], $__user['id']]);
  $favorited = (bool)$stmt->fetch();
}
?>
<div class="container">
  <?php ensure_channel_avatar_columns(); ?>
  <?php if (!empty($channel['cover_url'])): ?>
    <div class="channel-cover" style="width:100%;aspect-ratio:4/1;min-height:120px;border-radius:14px;overflow:hidden;margin-bottom:14px;background:#111 url('<?= e($channel['cover_url']) ?>') center/cover"></div>
  <?php endif; ?>
  <div class="channel-header">
    <img src="<?= e(channel_avatar_url($channel, 160)) ?>" alt="<?= e($channel['title']) ?>">
    <div>
      <h1><?= e($channel['title']) ?></h1>
      <div class="channel-stats"><?= (int)$channel['views'] ?> просмотров · <?= $channel['type'] === 'radio' ? 'Радио' : 'Телеканал' ?></div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
      <button type="button" id="like-btn" class="btn btn-outline btn-sm <?= $liked ? 'liked' : '' ?>" onclick="toggleLike()" <?= $__user ? '' : 'disabled title="Войдите, чтобы поставить лайк"' ?>>
        ♥ <span id="like-count"><?= $likeCount ?></span>
      </button>
      <button type="button" id="fav-btn" class="btn btn-outline btn-sm <?= $favorited ? 'favorited' : '' ?>" onclick="toggleFavorite()" <?= $__user ? '' : 'disabled title="Войдите, чтобы добавить в избранное"' ?>>
        <?= $favorited ? '★ В избранном' : '☆ В избранное' ?>
      </button>
    </div>
  </div>

  <div class="channel-layout">
    <div>
      <div class="player-wrap" id="player-wrap">
        <div id="playerjs-container"></div>
        <?php if ($channel['logo_url']): ?><img class="player-logo" src="<?= e($channel['logo_url']) ?>" alt=""><?php endif; ?>
      </div>

      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px">
        <?php if ($activeSource): ?><span class="live-badge" id="live-badge">● В ЭФИРЕ</span><?php endif; ?>
        <span style="font-size:13px;color:var(--text-dim)">Сейчас: <b id="on-air-title" style="color:var(--text)"><?= e($activeSource['program_title'] ?? ($activeSource ? 'Прямой эфир' : 'нет вещания')) ?></b></span>
      </div>
      <div style="font-size:12px;color:var(--text-dim);margin-top:4px" id="next-program-wrap">
        <?php if ($nextProgram): ?>Далее: <span id="next-program"><?= e($days[(int)$nextProgram['day_of_week']]) ?>, <?= e(substr($nextProgram['start_time'],0,5)) ?> — <?= e($nextProgram['program_title'] ?: $nextProgram['source_name']) ?></span><?php else: ?><span id="next-program"></span><?php endif; ?>
      </div>

      <?php if ($channel['description']): ?><p style="color:var(--text-dim);margin-top:16px"><?= e($channel['description']) ?></p><?php endif; ?>

      <h3 style="margin-top:28px">Расписание эфира (время МСК)</h3>
      <table class="schedule-table">
        <thead><tr><th>День</th><th>Время</th><th>Программа</th></tr></thead>
        <tbody>
          <?php foreach ($schedule as $s): ?>
          <tr data-schedule-id="<?= (int)$s['id'] ?>" class="<?= (isset($activeSource['schedule_id']) && (int)$activeSource['schedule_id'] === (int)$s['id']) ? 'active-row' : '' ?>">
            <td><?= $days[(int)$s['day_of_week']] ?></td><td><?= e(substr($s['start_time'],0,5)) ?>–<?= e(substr($s['end_time'],0,5)) ?></td><td><?= e($s['program_title'] ?: $s['source_name']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($schedule)): ?><tr><td colspan="3" style="color:var(--text-dim)">Расписание не задано, вещание идёт по источнику по умолчанию</td></tr><?php endif; ?>
        </tbody>
      </table>

      <h3 id="comments" style="margin-top:28px">Комментарии (<span id="comments-count"><?= count($comments) ?></span>)</h3>
      <?php if ($__user): ?>
        <form method="POST" action="/comment_add" id="channel-comment-form" style="margin-bottom:18px">
          <?= csrf_field() ?>
          <input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>">
          <textarea id="comment-input" name="message" placeholder="Написать комментарий..." maxlength="1000" required></textarea>
          <?php if ($stickers): ?>
          <div class="sticker-row" style="padding:8px 0 0">
            <?php foreach ($stickers as $s): ?><img src="<?= e($s['image_url']) ?>" title="<?= e($s['code']) ?>" class="sticker-pick" style="width:26px;height:26px;object-fit:contain;cursor:pointer;border-radius:6px" onclick="insertCommentSticker('<?= e(addslashes($s['code'])) ?>')"><?php endforeach; ?>
          </div>
          <?php endif; ?>
          <button class="btn btn-primary btn-sm" style="margin-top:8px" type="submit">Отправить</button>
        </form>
      <?php else: ?>
        <p style="color:var(--text-dim);font-size:13px"><a href="/auth/login.php" style="color:var(--accent-2)">Войдите</a>, чтобы оставить комментарий.</p>
      <?php endif; ?>
      <?php
        $__maxCommentId = 0;
        foreach ($comments as $__c) { if ((int)$__c['id'] > $__maxCommentId) $__maxCommentId = (int)$__c['id']; }
      ?>
      <div id="comments-list" data-after="<?= (int)$__maxCommentId ?>" style="display:flex;flex-direction:column;gap:14px">
        <?php if (empty($comments)): ?>
          <p id="comments-empty" style="color:var(--text-dim);font-size:13px">Комментариев пока нет — будьте первым.</p>
        <?php endif; ?>
        <?php foreach ($comments as $c): ?>
          <div class="channel-comment" data-id="<?= (int)$c['id'] ?>" style="border-bottom:1px solid var(--border);padding-bottom:12px">
            <b><a href="/profile.php?username=<?= e($c['username']) ?>" style="color:inherit;text-decoration:none"><?= e($c['username']) ?></a></b><?= verify_badge((bool)$c['is_verified']) ?> <span style="color:var(--text-dim);font-size:12px"><?= e($c['created_at']) ?></span>
            <?php if ($isModerator): ?>
              <form method="POST" action="/comment_delete" style="display:inline" onsubmit="return confirm('Удалить комментарий и заблокировать автора на канале?')">
                <?= csrf_field() ?>
                <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>">
                <button type="submit" style="background:none;border:none;color:var(--danger);font-size:11px;cursor:pointer">удалить и заблокировать</button>
              </form>
            <?php endif; ?>
            <p style="margin:4px 0 0"><?= render_with_stickers($c['message'], $stickers) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script src="playerjs.js"></script>
<script>
  const channelId = <?= (int)$channel['id'] ?>;
  const currentUserId = <?= $__user ? (int)$__user['id'] : 'null' ?>;
  const currentUsername = <?= $__user ? json_encode($__user['username']) : 'null' ?>;
  const isModerator = <?= $isModerator ? 'true' : 'false' ?>;
  const stickers = <?= json_encode(array_map(fn($s) => ['code' => $s['code'], 'url' => $s['image_url']], $stickers)) ?>;
  let lastId = 0;
  let currentSourceId = <?= $activeSource ? (int)$activeSource['id'] : 'null' ?>;
  let playerInstance = null;

  window.__currentChannelSlug = <?= json_encode($channel['slug']) ?>;
  try {
    localStorage.setItem('streamlive_miniplayer', JSON.stringify({
      slug: <?= json_encode($channel['slug']) ?>,
      title: <?= json_encode($channel['title']) ?>
    }));
  } catch (e) {}

  function escapeHtml(s) {
    return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function renderTextWithStickers(text) {
    let html = escapeHtml(text);
    for (const s of stickers) {
      if (!s.code || html.indexOf(escapeHtml(s.code)) === -1) continue;
      const img = `<img src="${escapeHtml(s.url)}" alt="${escapeHtml(s.code)}" class="inline-sticker" style="width:22px;height:22px;vertical-align:middle;object-fit:contain;display:inline-block">`;
      html = html.split(escapeHtml(s.code)).join(img);
    }
    return html;
  }

  function renderMessage(m) {
    const box = document.getElementById('chat-messages');
    if (!box) return;
    const row = document.createElement('div');
    row.className = 'chat-msg';
    row.dataset.id = m.id;
    let delBtn = isModerator ? `<span class="del-btn" onclick="deleteMsg(${m.id})">удалить</span>` : '';
    // messageHtml приходит уже безопасно отрендеренным с сервера (экранирование +
    // подстановка стикеров — и из стикеров канала, и из общих стикер-паков).
    const body = m.messageHtml || renderTextWithStickers(m.message);
    row.innerHTML = `<b>${escapeHtml(m.username)}:</b> ${body} ${delBtn}`;
    box.appendChild(row);
    box.scrollTop = box.scrollHeight;
    lastId = Math.max(lastId, m.id);
  }

  async function poll() {
    try {
      const resp = await fetch(`/chat_poll.php?channel_id=${channelId}&after=${lastId}`);
      const data = await resp.json();
      (data.messages || []).forEach(renderMessage);
      (data.deleted || []).forEach(id => {
        const el = document.querySelector(`.chat-msg[data-id="${id}"]`);
        if (el) el.remove();
      });
    } catch (e) {}
    setTimeout(poll, 2000);
  }
  poll();

  async function sendMessage() {
    const input = document.getElementById('chat-input');
    if (!input || !input.value.trim()) return;
    const resp = await fetch('/chat_send', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ channel_id: channelId, message: input.value })
    });
    const data = await resp.json();
    if (!data.ok) { alert(data.error || 'Не удалось отправить сообщение'); return; }
    input.value = '';
  }
  document.getElementById('chat-input')?.addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(); });

  function insertSticker(code) {
    const input = document.getElementById('chat-input');
    if (input) input.value += ` ${code} `;
  }
  function insertCommentSticker(code) {
    const input = document.getElementById('comment-input');
    if (input) input.value += ` ${code} `;
  }

  async function deleteMsg(messageId) {
    await fetch('/chat_delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ channel_id: channelId, message_id: messageId })
    });
    const el = document.querySelector(`.chat-msg[data-id="${messageId}"]`);
    if (el) el.remove();
  }

  // ---------- PlayerJS ----------
  function destroyPlayer() {
    if (playerInstance) {
      try { playerInstance.destroy(); } catch (e) {}
      playerInstance = null;
    }
    // Очищаем контейнер от лишних элементов (если PlayerJS их оставляет)
    const container = document.getElementById('playerjs-container');
    if (container) container.innerHTML = '';
  }

  function initPlayer(source, isPaused) {
    destroyPlayer();
    const container = document.getElementById('playerjs-container');
    if (!container) return;

    if (!source) {
      const msg = isPaused ? 'Трансляция завершена. Владелец канала временно остановил эфир.' : 'Сейчас нет активного эфира';
      container.innerHTML = '<div style="color:#888;display:flex;align-items:center;justify-content:center;height:100%;text-align:center;padding:20px">' + msg + '</div>';
      return;
    }

    const options = {
      id: 'playerjs-container',
      file: source.url,
      autoplay: true,
      muted: true,
      poster: <?= json_encode($channel['logo_url']) ?: '' ?>,
      // PlayerJS сам определит тип по расширению, но можно явно:
      // type: (source.type === 'm3u8' ? 'hls' : source.type === 'mp4' ? 'mp4' : 'iframe')
    };

    // Для iframe используем другой подход: вставляем iframe напрямую, так как PlayerJS может не поддерживать iframe-вставки.
    if (source.type === 'iframe') {
      container.innerHTML = `<iframe src="${escapeHtml(source.url)}" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen style="width:100%;height:100%;border:0;"></iframe>`;
      return;
    }

    try {
      playerInstance = new Playerjs(options);
      // Для MP4 синхронизируем время, если есть sync_epoch
      if (source.type === 'mp4' && source.sync_epoch) {
        startMp4Sync(source.sync_epoch);
      } else {
        stopMp4Sync();
      }
    } catch (e) {
      console.error('PlayerJS init error:', e);
      container.innerHTML = '<div style="color:#f44;display:flex;align-items:center;justify-content:center;height:100%">Ошибка загрузки плеера</div>';
    }
  }

  // ---------- Синхронизация MP4 (аналог syncLiveMp4) ----------
  let mp4SyncInterval = null;
  let mp4SyncDisabled = false;

  function stopMp4Sync() {
    if (mp4SyncInterval) {
      clearInterval(mp4SyncInterval);
      mp4SyncInterval = null;
    }
    mp4SyncDisabled = false;
  }

  function startMp4Sync(epoch) {
    stopMp4Sync();
    if (!epoch) return;
    mp4SyncDisabled = false;

    function applySync() {
      if (mp4SyncDisabled || !playerInstance) return;
      try {
        const duration = playerInstance.getDuration();
        if (!duration || !isFinite(duration)) return;
        const target = ((Date.now() / 1000 - epoch) % duration + duration) % duration;
        const current = playerInstance.getCurrentTime();
        if (Math.abs(current - target) > 2.5) {
          playerInstance.setCurrentTime(target);
        }
      } catch (e) {
        mp4SyncDisabled = true;
      }
    }

    // Подождём, пока плеер загрузит метаданные
    const checkReady = setInterval(() => {
      if (playerInstance && playerInstance.getDuration && playerInstance.getDuration() > 0) {
        clearInterval(checkReady);
        applySync();
        mp4SyncInterval = setInterval(applySync, 8000);
      }
    }, 500);
    // Защита от бесконечного ожидания
    setTimeout(() => clearInterval(checkReady), 10000);
  }

  // ---------- Переключение источников по расписанию ----------
  async function checkSchedule() {
    try {
      const resp = await fetch(`/now_playing.php?channel_id=${channelId}`);
      const data = await resp.json();
      if (!data.ok) return;

      const source = data.source;
      const newId = source ? source.id : null;

      if (newId !== currentSourceId) {
        currentSourceId = newId;
        initPlayer(source, data.is_paused);
      }

      const liveBadge = document.getElementById('live-badge');
      if (liveBadge) liveBadge.style.display = source ? '' : 'none';

      const titleEl = document.getElementById('on-air-title');
      if (titleEl) titleEl.textContent = source ? (source.program_title || 'Прямой эфир') : (data.is_paused ? 'трансляция завершена' : 'нет вещания');

      const nextEl = document.getElementById('next-program');
      if (nextEl) nextEl.textContent = data.next ? `${data.next.day}, ${data.next.start_time} — ${data.next.program_title}` : '';

      document.querySelectorAll('.schedule-table tr[data-schedule-id]').forEach(row => {
        const rowId = row.getAttribute('data-schedule-id');
        row.classList.toggle('active-row', !!(source && source.schedule_id && String(source.schedule_id) === rowId));
      });
    } catch (e) {}

    // Проверка статуса потока (офлайн/онлайн) — для ЛЮБОГО активного m3u8-источника,
    // не только через RTMP-relay. Если стрим пропал — показываем сообщение прямо на
    // плеере, без перезагрузки страницы; когда появится снова — плеер сам вернётся.
    try {
      const liveResp = await fetch(`/check_stream_live.php?channel_id=${channelId}`);
      const liveData = await liveResp.json();
      const badge = document.getElementById('live-badge');
      const container = document.getElementById('playerjs-container');
      if (liveData.applicable && badge) {
        badge.style.display = '';
        badge.textContent = liveData.live ? '● В ЭФИРЕ' : '○ ОФЛАЙН';
        badge.classList.toggle('live-badge-offline', !liveData.live);
        if (!liveData.live && container && !container.dataset.offlineShown) {
          destroyPlayer();
          container.innerHTML = '<div style="color:#888;display:flex;align-items:center;justify-content:center;height:100%;text-align:center;padding:20px">Стрим сейчас выключен. Плеер включится сам, как только вещание возобновится.</div>';
          container.dataset.offlineShown = '1';
        } else if (liveData.live && container && container.dataset.offlineShown) {
          container.dataset.offlineShown = '';
          currentSourceId = null; // форсируем переинициализацию плеера на следующем тике checkSchedule
        }
      }
    } catch (e) {}
  }

  // Запускаем первый раз и дальше каждые 15 секунд
  setTimeout(() => {
    initPlayer(<?= json_encode($activeSource) ?>, <?= !empty($channel['is_broadcast_paused']) ? 'true' : 'false' ?>);
  }, 100); // небольшая задержка, чтобы DOM точно отрисовался
  setInterval(checkSchedule, 15000);

  // ---------- Лайки / избранное ----------
  async function toggleLike() {
    const resp = await fetch('/channel_like', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ channel_id: channelId })
    });
    const data = await resp.json();
    if (!data.ok) { alert(data.error || 'Не удалось поставить лайк'); return; }
    document.getElementById('like-count').textContent = data.count;
    document.getElementById('like-btn').classList.toggle('liked', data.liked);
  }

  async function toggleFavorite() {
    const resp = await fetch('/channel_favorite', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ channel_id: channelId })
    });
    const data = await resp.json();
    if (!data.ok) { alert(data.error || 'Не удалось обновить избранное'); return; }
    const btn = document.getElementById('fav-btn');
    btn.classList.toggle('favorited', data.favorited);
    btn.textContent = data.favorited ? '★ В избранном' : '☆ В избранное';
  }
</script>

<script>
(function rememberChannelInterest(){
  const id = <?= (int)$channel['id'] ?>;
  let viewed = (document.cookie.match(/(?:^|; )viewed_channels=([^;]*)/)||[])[1];
  viewed = viewed ? decodeURIComponent(viewed).split(',').filter(Boolean) : [];
  if (!viewed.includes(String(id))) viewed.unshift(String(id));
  document.cookie = 'viewed_channels=' + encodeURIComponent(viewed.slice(0,80).join(',')) + '; path=/; max-age=31536000';
})();
</script>
<?php

?>
<script>
(function () {
  var list = document.getElementById('comments-list');
  if (!list) return;
  var channelId = <?= (int)$channel['id'] ?>;
  var after = parseInt(list.getAttribute('data-after') || '0', 10) || 0;
  var countEl = document.getElementById('comments-count');
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function appendComment(c) {
    if (list.querySelector('.channel-comment[data-id="' + c.id + '"]')) return;
    var empty = document.getElementById('comments-empty');
    if (empty) empty.remove();
    var div = document.createElement('div');
    div.className = 'channel-comment';
    div.setAttribute('data-id', c.id);
    div.style.cssText = 'border-bottom:1px solid var(--border);padding-bottom:12px';
    div.innerHTML = '<b><a href="/profile.php?username=' + encodeURIComponent(c.username) + '" style="color:inherit;text-decoration:none">' + esc(c.username) + '</a></b>' +
      (c.is_verified ? ' ✓' : '') +
      ' <span style="color:var(--text-dim);font-size:12px">' + esc(c.created_at) + '</span>' +
      '<p style="margin:4px 0 0">' + esc(c.message) + '</p>';
    list.insertBefore(div, list.firstChild);
    after = Math.max(after, c.id);
    list.setAttribute('data-after', String(after));
    if (countEl) countEl.textContent = String(list.querySelectorAll('.channel-comment').length);
  }
  function pollComments() {
    fetch('/comments_poll.php?channel_id=' + channelId + '&after=' + after, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        (d.comments || []).forEach(appendComment);
        if (d.max_id) after = Math.max(after, d.max_id);
      })
      .catch(function () {})
      .finally(function () { setTimeout(pollComments, 10000); });
  }
  setTimeout(pollComments, 10000);
})();
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>