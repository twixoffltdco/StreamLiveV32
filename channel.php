<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/paid_access.php';

// ===== ВСТРОЕННОЕ API ДЛЯ ИНФОРМАЦИИ О КАНАЛЕ =====
if (isset($_GET['action']) && $_GET['action'] === 'info' && isset($_GET['channel_id'])) {
    header('Content-Type: application/json');
    $channel_id = (int)$_GET['channel_id'];
    if (!$channel_id) { echo json_encode(['ok' => false, 'error' => 'No channel id']); exit; }
    $stmt = db()->prepare('SELECT description FROM channels WHERE id = ?');
    $stmt->execute([$channel_id]);
    $channel = $stmt->fetch();
    if (!$channel) { echo json_encode(['ok' => false, 'error' => 'Channel not found']); exit; }
    $description = $channel['description'] ?? '';

    $stmt = db()->prepare('SELECT sch.*, s.name as source_name FROM schedule sch JOIN sources s ON s.id = sch.source_id WHERE channel_id = ? ORDER BY day_of_week, start_time');
    $stmt->execute([$channel_id]);
    $schedule = $stmt->fetchAll();
    $days = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    $scheduleFormatted = array_map(function($s) use ($days) {
        return [
            'day' => $days[(int)$s['day_of_week']],
            'start_time' => substr($s['start_time'], 0, 5),
            'end_time' => substr($s['end_time'], 0, 5),
            'program_title' => $s['program_title'],
            'source_name' => $s['source_name']
        ];
    }, $schedule);

    $stmt = db()->prepare('SELECT cm.id, cm.message, cm.created_at, u.username FROM comments cm JOIN users u ON u.id = cm.user_id WHERE cm.channel_id = ? AND cm.is_deleted = 0 ORDER BY cm.created_at DESC LIMIT 100');
    $stmt->execute([$channel_id]);
    $comments = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'description' => $description, 'schedule' => $scheduleFormatted, 'comments' => $comments]);
    exit;
}

// ===== ОСНОВНАЯ ЛОГИКА СТРАНИЦЫ =====
// Принимаем slug (красивый URL), а также id / c — их отдаёт оболочка Платформа.
$slug = trim((string)($_GET['slug'] ?? ''));
$byId = (int)($_GET['id'] ?? 0);
$byC  = trim((string)($_GET['c'] ?? ''));

$__user = current_user();

$channel = null;
if ($slug !== '') {
    $stmt = db()->prepare('SELECT * FROM channels WHERE slug = ?');
    $stmt->execute([$slug]);
    $channel = $stmt->fetch();
} elseif ($byId > 0) {
    $stmt = db()->prepare('SELECT * FROM channels WHERE id = ?');
    $stmt->execute([$byId]);
    $channel = $stmt->fetch();
} elseif ($byC !== '') {
    // c= может быть и slug, и числовой id
    if (ctype_digit($byC)) {
        $stmt = db()->prepare('SELECT * FROM channels WHERE id = ?');
        $stmt->execute([(int)$byC]);
    } else {
        $stmt = db()->prepare('SELECT * FROM channels WHERE slug = ?');
        $stmt->execute([$byC]);
    }
    $channel = $stmt->fetch();
}

if (!$slug && !$byId && $byC === '') {
    header('Location: /catalog.php');
    exit;
}

if (!$channel) {
    http_response_code(404);
    $pageTitle = 'Канал не найден';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="container"><div class="empty-state"><h2>Канал не найден</h2><p>Такого канала не существует</p><a href="/catalog.php" class="btn btn-primary" style="margin-top:14px">В каталог</a></div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Не-approved: владелец, модератор и админ могут смотреть; остальные — 403.
$isOwner = $__user && (int)$__user['id'] === (int)$channel['owner_id'];
$isStaff = $__user && in_array($__user['role'] ?? '', ['admin', 'moderator'], true);
if ($channel['status'] !== 'approved' && !$isOwner && !$isStaff) {
    http_response_code(403);
    $pageTitle = 'Канал недоступен';
    require_once __DIR__ . '/includes/header.php';
    $msg = $channel['status'] === 'pending' ? 'Канал ещё проходит модерацию и пока не допущен в каталог' : 'Канал не был допущен в каталог' . (!empty($channel['reject_reason']) ? ': ' . e($channel['reject_reason']) : '');
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

paid_require_access($channel, $__user);

// Увеличиваем просмотры для текущего канала
db()->prepare('UPDATE channels SET views = views + 1 WHERE id = ?')->execute([$channel['id']]);

// ===== ПЕРСОНАЛЬНЫЕ РЕКОМЕНДАЦИИ =====
// Лента учитывает лайки/избранное пользователя, популярность, свежесть и просмотренные каналы из cookies.
$recommended = recommended_channels($__user, (int)$channel['id'], 19);
$allChannels = array_merge([$channel], $recommended);

// Для каждого канала вычисляем активный источник, следующую программу, лайки, избранное, стикеры
foreach ($allChannels as &$c) {
    $c['active_source'] = resolve_active_source($c);
    $c['next_program'] = find_next_program($c['id']);
    $lc = db()->prepare('SELECT COUNT(*) FROM channel_likes WHERE channel_id = ?');
    $lc->execute([$c['id']]);
    $c['like_count'] = (int)$lc->fetchColumn();
    $c['liked'] = false;
    if ($__user) {
        $stmtLike = db()->prepare('SELECT id FROM channel_likes WHERE channel_id = ? AND user_id = ?');
        $stmtLike->execute([$c['id'], $__user['id']]);
        $c['liked'] = (bool)$stmtLike->fetch();
    }
    $c['favorited'] = false;
    if ($__user) {
        $stmtFav = db()->prepare('SELECT id FROM favorites WHERE channel_id = ? AND user_id = ?');
        $stmtFav->execute([$c['id'], $__user['id']]);
        $c['favorited'] = (bool)$stmtFav->fetch();
    }
    $stmtStickers = db()->prepare('SELECT * FROM stickers WHERE channel_id = ?');
    $stmtStickers->execute([$c['id']]);
    $c['stickers'] = $stmtStickers->fetchAll();
}

// SEO
$pageTitle = $channel['seo_title'] ?: $channel['title'];
$seoDescription = $channel['seo_description'] ?: $channel['description'];
$seoKeywords = $channel['seo_keywords'] ?: '';

// CSRF-токен для комментариев
$csrf_token = csrf_token();
require_once __DIR__ . '/includes/headershorts.php';
?>

<style>
  body { overflow: hidden; background: #000; margin: 0; padding: 0; }
  .navbar { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; background: rgba(0,0,0,0.8); }
  .shorts-container {
    height: 100vh;
    scroll-snap-type: y mandatory;
    overflow-y: scroll;
    padding-top: 60px;
    scroll-behavior: smooth;
  }
  .short-card {
    height: calc(100vh - 60px);
    scroll-snap-align: start;
    position: relative;
    display: flex;
    flex-direction: column;
    background: #000;
  }
  .short-video-wrap {
    flex: 1;
    position: relative;
    background: #000;
    overflow: hidden;
  }
  .short-video-wrap iframe,
  .short-video-wrap video {
    width: 100%;
    height: 100%;
    border: none;
    object-fit: contain;
  }
  /* Контейнер для PlayerJS занимает всё пространство */
  .short-video-wrap #playerjs-container-<?= $channel['id'] ?> {
    width: 100%;
    height: 100%;
  }
  .short-overlay {
    position: absolute;
    bottom: 20px;
    left: 20px;
    right: 80px;
    color: #fff;
    text-shadow: 0 2px 4px rgba(0,0,0,0.8);
    pointer-events: none;
    z-index: 2;
  }
  .short-overlay h2 { margin: 0; font-size: 1.2rem; cursor: pointer; pointer-events: auto; }
  .short-overlay .sub { font-size: 0.85rem; opacity: 0.8; }
  .short-overlay .live-badge { display: inline-block; background: #ff0040; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; margin-right: 6px; pointer-events: none; }
  .short-overlay .live-badge.offline { background: #555; }
  .short-actions {
    position: absolute;
    right: 15px;
    bottom: 100px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    align-items: center;
    z-index: 3;
  }
  .action-btn {
    width: 50px;
    height: 50px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    cursor: pointer;
    font-size: 1.5rem;
    backdrop-filter: blur(5px);
    border: none;
    flex-direction: column;
    font-size: 1.2rem;
    line-height: 1.2;
  }
  .action-btn span { font-size: 0.7rem; margin-top: 2px; }
  .action-btn.liked { color: #ff2d55; }
  .action-btn.favorited { color: #ffcc00; }
  .chat-toggle-btn { background: rgba(124, 92, 255, 0.6); }
  .info-toggle-btn { background: rgba(255, 255, 255, 0.2); }
  
  .short-chat-panel, .short-info-panel {
    position: absolute;
    top: 0;
    right: -350px;
    width: 350px;
    height: 100%;
    background: rgba(0,0,0,0.9);
    transition: right 0.3s ease;
    z-index: 10;
    display: flex;
    flex-direction: column;
    border-left: 1px solid #333;
  }
  .short-chat-panel.active, .short-info-panel.active { right: 0; }
  .chat-messages { flex: 1; overflow-y: auto; padding: 15px; color: #fff; font-size: 0.9rem; }
  .chat-msg { margin-bottom: 8px; line-height: 1.4; word-wrap: break-word; }
  .chat-msg b { color: #7c5cff; }
  .chat-msg .del-btn { color: #ff0040; font-size: 0.7rem; cursor: pointer; margin-left: 8px; }
  .chat-input-row { padding: 10px; display: flex; gap: 8px; border-top: 1px solid #333; }
  .chat-input-row input { flex: 1; background: #222; border: 1px solid #444; color: #fff; padding: 8px; border-radius: 4px; font-family: inherit; }
  .chat-input-row button { padding: 8px 12px; }
  .info-content { padding: 15px; color: #fff; overflow-y: auto; flex: 1; }
  .info-content h4 { color: #7c5cff; margin: 12px 0 6px; }
  .info-content table { width: 100%; font-size: 0.85rem; border-collapse: collapse; }
  .info-content td, .info-content th { padding: 4px 6px; border-bottom: 1px solid #333; }
  .info-content .comment-item { border-bottom: 1px solid #222; padding: 8px 0; }
  .info-content .comment-item b { color: #7c5cff; }
  .sticker-pick { width: 26px; height: 26px; object-fit: contain; cursor: pointer; border-radius: 6px; margin: 2px; }
  .inline-sticker { width: 22px; height: 22px; vertical-align: middle; object-fit: contain; display: inline-block; }
  .player-logo {
    position: absolute;
    top: 10px;
    left: 10px;
    max-width: 60px;
    max-height: 60px;
    z-index: 1;
    background: rgba(0,0,0,0.5);
    border-radius: 8px;
    padding: 4px;
    object-fit: contain;
  }
  @media (max-width: 768px) {
    .short-chat-panel, .short-info-panel { width: 100%; right: -100%; }
    .short-overlay { bottom: 80px; left: 10px; right: 70px; }
    .short-actions { right: 10px; bottom: 80px; gap: 12px; }
    .chat-input-row { padding-bottom: calc(10px + 58px + env(safe-area-inset-bottom, 0px)); }
    .action-btn { width: 44px; height: 44px; font-size: 1rem; }
    .short-overlay h2 { font-size: 1rem; }
  }
  .nav-arrows {
    position: fixed;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    z-index: 999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
  }
  .nav-arrows button {
    pointer-events: auto;
    background: rgba(255,255,255,0.2);
    border: none;
    color: #fff;
    font-size: 2rem;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    backdrop-filter: blur(5px);
    cursor: pointer;
    transition: background 0.2s;
  }
  .nav-arrows button:hover { background: rgba(255,255,255,0.4); }
  @media (max-width: 768px) { .nav-arrows { display: none; } }
  .info-tab { flex:1; padding:8px; background:transparent; border:none; color:#888; cursor:pointer; font-weight:bold; border-bottom:2px solid transparent; transition: all 0.2s; }
  .info-tab.active { color:#fff; border-bottom:2px solid #7c5cff; }
</style>

<div class="shorts-container" id="shorts-container">
  <?php foreach ($allChannels as $index => $c): 
    $activeSource = $c['active_source'];
    $nextProg = $c['next_program'];
    $isLive = $activeSource !== null;
    $stickersJson = json_encode(array_map(fn($s) => ['code' => $s['code'], 'url' => $s['image_url']], $c['stickers']));
  ?>
    <div class="short-card" data-id="<?= $c['id'] ?>" data-slug="<?= $c['slug'] ?>" data-stickers='<?= $stickersJson ?>' data-owner="<?= (int)$c['owner_id'] ?>" data-source-type="<?= $activeSource ? e($activeSource['type']) : '' ?>" data-source-url="<?= $activeSource ? e($activeSource['url']) : '' ?>" data-sync-epoch="<?= $activeSource && $activeSource['type'] === 'mp4' ? (int)strtotime($activeSource['created_at']) : '' ?>" data-logo="<?= e($c['logo_url'] ?? '') ?>" data-is-paused="<?= !empty($c['is_broadcast_paused']) ? '1' : '0' ?>">
      <div class="short-video-wrap" id="wrap-<?= $c['id'] ?>">
        <!-- Логотип будет добавлен через JS, но если он есть – показываем его сразу поверх плеера -->
        <?php if ($c['logo_url']): ?><img class="player-logo" src="<?= e($c['logo_url']) ?>" alt=""><?php endif; ?>
      </div>
      <div class="short-overlay">
        <h2 onclick="window.location.href='/channel.php?slug=<?= $c['slug'] ?>'"><?= e($c['title']) ?></h2>
        <div class="sub">
          <?php if ($isLive): ?><span class="live-badge" data-live="1">● В ЭФИРЕ</span><?php else: ?><span class="live-badge offline" data-live="0">○ ОФЛАЙН</span><?php endif; ?>
          <span><?= (int)$c['views'] ?> просмотров</span>
          <?php if ($nextProg): ?>
            <span style="margin-left:10px;font-size:0.75rem;">Далее: <?= e($nextProg['program_title'] ?: $nextProg['source_name']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="short-actions">
        <button type="button" class="action-btn short-like-btn <?= $c['liked'] ? 'liked' : '' ?>" data-id="<?= (int)$c['id'] ?>" onclick="toggleShortLike(this)">
          ♥ <span><?= (int)$c['like_count'] ?></span>
        </button>
        <button type="button" class="action-btn short-fav-btn <?= $c['favorited'] ? 'favorited' : '' ?>" data-id="<?= (int)$c['id'] ?>" onclick="toggleShortFavorite(this)">
          <?= $c['favorited'] ? '★' : '☆' ?>
        </button>
        <button type="button" class="action-btn chat-toggle-btn" onclick="toggleChat(<?= $c['id'] ?>)">💬</button>
        <button type="button" class="action-btn info-toggle-btn" onclick="toggleInfo(<?= $c['id'] ?>)">ℹ️</button>
        <a href="/channel-pc.php?slug=<?= $c['slug'] ?>" class="action-btn" style="text-decoration:none;" title="Открыть страницу канала">🔗</a>
      </div>
      <!-- Чат панель -->
      <div class="short-chat-panel" id="chat-panel-<?= $c['id'] ?>">
        <div style="padding:15px; border-bottom:1px solid #333; display:flex; justify-content:space-between; align-items:center">
          <span style="color:#fff;font-weight:bold">Чат: <?= e($c['title']) ?></span>
          <button style="background:none;border:none;color:#fff;cursor:pointer;font-size:1.2rem" onclick="toggleChat(<?= $c['id'] ?>)">✕</button>
        </div>
        <div class="chat-messages" id="chat-messages-<?= $c['id'] ?>"></div>
        <div class="chat-input-row">
          <?php if ($__user): ?>
            <input type="text" id="chat-input-<?= $c['id'] ?>" placeholder="Написать..." onkeydown="if(event.key==='Enter') sendShortMessage(<?= $c['id'] ?>)">
            <button class="btn btn-primary btn-sm" onclick="sendShortMessage(<?= $c['id'] ?>)">➤</button>
          <?php else: ?>
            <a href="/auth/login.php" style="color:#7c5cff;font-size:0.8rem;text-align:center;width:100%">Войдите, чтобы писать</a>
          <?php endif; ?>
        </div>
      </div>
      <!-- Инфо панель с вкладками -->
      <div class="short-info-panel" id="info-panel-<?= $c['id'] ?>">
        <div style="padding:15px; border-bottom:1px solid #333; display:flex; justify-content:space-between; align-items:center">
          <span style="color:#fff;font-weight:bold">Инфо: <?= e($c['title']) ?></span>
          <button style="background:none;border:none;color:#fff;cursor:pointer;font-size:1.2rem" onclick="toggleInfo(<?= $c['id'] ?>)">✕</button>
        </div>
        <div style="display:flex; border-bottom:1px solid #333; background:#111;">
          <button class="info-tab active" data-tab="about" onclick="switchInfoTab(<?= $c['id'] ?>, 'about')">О канале</button>
          <button class="info-tab" data-tab="schedule" onclick="switchInfoTab(<?= $c['id'] ?>, 'schedule')">Расписание</button>
          <button class="info-tab" data-tab="comments" onclick="switchInfoTab(<?= $c['id'] ?>, 'comments')">Комментарии</button>
        </div>
        <div class="info-content" id="info-content-<?= $c['id'] ?>">
          <div style="text-align:center;color:#888;">Загрузка...</div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="nav-arrows">
  <button onclick="scrollToPrev()">▲</button>
  <button onclick="scrollToNext()">▼</button>
</div>

<!-- Подключаем PlayerJS -->
<script src="playerjs.js"></script>
<script>
// CSRF-токен для комментариев
const csrfToken = <?= json_encode($csrf_token) ?>;

const activeChats = new Set();
const lastMessageIds = {};
const infoData = {};
const channelData = <?= json_encode($allChannels) ?>;
const currentUserId = <?= $__user ? (int)$__user['id'] : 'null' ?>;
const currentUsername = <?= $__user ? json_encode($__user['username']) : 'null' ?>;

// ======== НАВИГАЦИЯ ========
function scrollToNext() {
    const container = document.getElementById('shorts-container');
    const cards = container.querySelectorAll('.short-card');
    for (let i = 0; i < cards.length; i++) {
        if (cards[i].getBoundingClientRect().top >= 0 && cards[i].getBoundingClientRect().top < window.innerHeight) {
            if (i < cards.length - 1) cards[i+1].scrollIntoView({ behavior: 'smooth' });
            break;
        }
    }
}
function scrollToPrev() {
    const container = document.getElementById('shorts-container');
    const cards = container.querySelectorAll('.short-card');
    for (let i = 0; i < cards.length; i++) {
        if (cards[i].getBoundingClientRect().top >= 0 && cards[i].getBoundingClientRect().top < window.innerHeight) {
            if (i > 0) cards[i-1].scrollIntoView({ behavior: 'smooth' });
            break;
        }
    }
}

// ======== ЧАТ ========
function toggleChat(id) {
    const panel = document.getElementById(`chat-panel-${id}`);
    panel.classList.toggle('active');
    if (panel.classList.contains('active')) {
        activeChats.add(id);
        if (!lastMessageIds[id]) {
            lastMessageIds[id] = 0;
            pollChat(id);
        }
    } else {
        activeChats.delete(id);
    }
}

async function pollChat(id) {
    if (!activeChats.has(id)) return;
    try {
        const resp = await fetch(`/chat_poll.php?channel_id=${id}&after=${lastMessageIds[id] || 0}`);
        if (!resp.ok) { setTimeout(() => pollChat(id), 3000); return; }
        const data = await resp.json();
        const box = document.getElementById(`chat-messages-${id}`);
        if (!box) return;
        (data.messages || []).forEach(m => {
            const row = document.createElement('div');
            row.className = 'chat-msg';
            row.dataset.id = m.id;
            const card = document.querySelector(`.short-card[data-id="${id}"]`);
            const isModerator = (currentUserId !== null) && (parseInt(card.dataset.owner) === currentUserId || <?= ($__user && $__user['role'] === 'admin') ? 'true' : 'false' ?>);
            let delBtn = isModerator ? `<span class="del-btn" onclick="deleteMsg(${id}, ${m.id})">удалить</span>` : '';
            const body = m.messageHtml || renderTextWithStickers(id, m.message);
            row.innerHTML = `<b>${escapeHtml(m.username)}:</b> ${body} ${delBtn}`;
            box.appendChild(row);
            box.scrollTop = box.scrollHeight;
            lastMessageIds[id] = Math.max(lastMessageIds[id] || 0, m.id);
        });
        (data.deleted || []).forEach(msgId => {
            const el = box.querySelector(`.chat-msg[data-id="${msgId}"]`);
            if (el) el.remove();
        });
    } catch (e) { /* ignore */ }
    setTimeout(() => pollChat(id), 2000);
}

function renderTextWithStickers(channelId, text) {
    const card = document.querySelector(`.short-card[data-id="${channelId}"]`);
    if (!card) return escapeHtml(text);
    let stickers;
    try { stickers = JSON.parse(card.dataset.stickers); } catch(e){ stickers = []; }
    let html = escapeHtml(text);
    for (const s of stickers) {
        const code = escapeHtml(s.code);
        const url = escapeHtml(s.url);
        const img = `<img src="${url}" alt="${code}" class="inline-sticker">`;
        html = html.split(code).join(img);
    }
    return html;
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function sendShortMessage(id) {
    const input = document.getElementById(`chat-input-${id}`);
    const message = input.value.trim();
    if (!message) return;
    input.disabled = true;
    try {
        const resp = await fetch('/chat_send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({ channel_id: id, message: message })
        });
        const data = await resp.json();
        if (data.ok) {
            input.value = '';
        } else {
            alert(data.error || 'Ошибка при отправке');
        }
        input.disabled = false;
    } catch (e) {
        alert('Ошибка сети');
        input.disabled = false;
    }
}

async function deleteMsg(channelId, messageId) {
    if (!confirm('Удалить сообщение?')) return;
    try {
        await fetch('/chat_delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel_id: channelId, message_id: messageId })
        });
        const box = document.getElementById(`chat-messages-${channelId}`);
        if (box) {
            const el = box.querySelector(`.chat-msg[data-id="${messageId}"]`);
            if (el) el.remove();
        }
    } catch(e) {}
}

// ======== ИНФО ПАНЕЛЬ (ВКЛАДКИ) ========
async function toggleInfo(id) {
    const panel = document.getElementById(`info-panel-${id}`);
    panel.classList.toggle('active');
    if (panel.classList.contains('active')) {
        if (!infoData[id]) {
            await loadInfoData(id);
        }
        switchInfoTab(id, 'about');
    }
}

async function loadInfoData(id) {
    try {
        const resp = await fetch(`/channel.php?action=info&channel_id=${id}`);
        const data = await resp.json();
        if (data.ok) {
            infoData[id] = data;
        } else {
            infoData[id] = { error: true };
        }
    } catch(e) {
        infoData[id] = { error: true };
    }
}

function switchInfoTab(id, tab) {
    const data = infoData[id];
    if (!data || data.error) {
        document.getElementById(`info-content-${id}`).innerHTML = '<p style="color:#888;">Ошибка загрузки данных</p>';
        return;
    }
    const content = document.getElementById(`info-content-${id}`);
    const tabs = document.querySelectorAll(`#info-panel-${id} .info-tab`);
    tabs.forEach(t => {
        t.classList.remove('active');
        if (t.dataset.tab === tab) t.classList.add('active');
    });

    let html = '';
    if (tab === 'about') {
        html = `<p>${escapeHtml(data.description || 'Нет описания')}</p>`;
    } else if (tab === 'schedule') {
        html = `<table style="width:100%;font-size:0.85rem;border-collapse:collapse;">
            <thead><tr><th>День</th><th>Время</th><th>Программа</th></tr></thead><tbody>`;
        if (data.schedule && data.schedule.length) {
            data.schedule.forEach(s => {
                html += `<tr><td>${s.day}</td><td>${s.start_time}–${s.end_time}</td><td>${escapeHtml(s.program_title || s.source_name)}</td></tr>`;
            });
        } else {
            html += `<tr><td colspan="3" style="color:#888;">Расписание не задано</td></tr>`;
        }
        html += `</tbody></table>`;
    } else if (tab === 'comments') {
        html = `<div style="margin-bottom:10px;">`;
        if (currentUserId) {
            html += `<div style="display:flex;gap:8px;margin-bottom:10px;">
                <input type="text" id="comment-input-${id}" placeholder="Написать комментарий..." style="flex:1;background:#222;border:1px solid #444;color:#fff;padding:8px;border-radius:4px;">
                <button class="btn btn-primary btn-sm" onclick="sendComment(${id})">Отправить</button>
            </div>`;
        } else {
            html += `<p style="color:#888;font-size:0.9rem;"><a href="/auth/login.php" style="color:#7c5cff;">Войдите</a>, чтобы комментировать</p>`;
        }
        let maxCid = 0;
        (data.comments || []).forEach(c => { if (c.id && c.id > maxCid) maxCid = c.id; });
        html += `<div id="comment-list-${id}" data-after="${maxCid}">`;
        if (data.comments && data.comments.length) {
            data.comments.forEach(c => {
                html += `<div class="comment-item" data-cid="${c.id||0}"><b>${escapeHtml(c.username)}</b> <span style="color:#888;font-size:0.75rem;">${escapeHtml(c.created_at)}</span><br>${renderTextWithStickers(id, c.message)}</div>`;
            });
        } else {
            html += `<p style="color:#888;">Комментариев пока нет.</p>`;
        }
        html += `</div></div>`;
    }
    content.innerHTML = html;
}

async function sendComment(id) {
    const input = document.getElementById(`comment-input-${id}`);
    const message = input.value.trim();
    if (!message) return;
    try {
        const resp = await fetch('/comment_add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `channel_id=${id}&message=${encodeURIComponent(message)}&csrf_token=${encodeURIComponent(csrfToken)}`
        });
        const text = await resp.text();
        if (text.includes('успешно') || text.includes('success') || text.trim() === '') {
            input.value = '';
            await loadInfoData(id);
            switchInfoTab(id, 'comments');
        } else {
            alert('Ошибка отправки комментария: ' + text);
        }
    } catch(e) {
        alert('Сетевая ошибка');
    }
}

// ======== ЛАЙКИ / ИЗБРАННОЕ ========
async function toggleShortLike(btn) {
    <?php if (!$__user): ?>
    location.href = '/auth/login.php';
    return;
    <?php endif; ?>
    const id = btn.dataset.id;
    try {
        const resp = await fetch('/channel_like', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel_id: id })
        });
        const data = await resp.json();
        if (data.ok) {
            btn.classList.toggle('liked', data.liked);
            btn.querySelector('span').textContent = data.count;
        }
    } catch (e) { console.error('Like error:', e); }
}

async function toggleShortFavorite(btn) {
    <?php if (!$__user): ?>
    location.href = '/auth/login.php';
    return;
    <?php endif; ?>
    const id = btn.dataset.id;
    try {
        const resp = await fetch('/channel_favorite', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel_id: id })
        });
        const data = await resp.json();
        if (data.ok) {
            btn.classList.toggle('favorited', data.favorited);
            btn.innerHTML = data.favorited ? '★' : '☆';
        }
    } catch (e) { console.error('Favorite error:', e); }
}

// ======== НОВЫЙ ПЛЕЕР НА PLAYERJS ========
const playerInstances = {};
const mp4SyncIntervals = {};
const mp4SyncDisabled = {};

function destroyPlayer(id) {
    if (playerInstances[id]) {
        try { playerInstances[id].destroy(); } catch(e) {}
        delete playerInstances[id];
    }
    if (mp4SyncIntervals[id]) {
        clearInterval(mp4SyncIntervals[id]);
        delete mp4SyncIntervals[id];
    }
    delete mp4SyncDisabled[id];
}

function startMp4Sync(id, epoch) {
    if (mp4SyncIntervals[id]) clearInterval(mp4SyncIntervals[id]);
    if (!epoch) return;
    mp4SyncDisabled[id] = false;

    function applySync() {
        if (mp4SyncDisabled[id] || !playerInstances[id]) return;
        try {
            const duration = playerInstances[id].getDuration();
            if (!duration || !isFinite(duration)) return;
            const target = ((Date.now() / 1000 - epoch) % duration + duration) % duration;
            const current = playerInstances[id].getCurrentTime();
            if (Math.abs(current - target) > 2.5) {
                playerInstances[id].setCurrentTime(target);
            }
        } catch (e) {
            mp4SyncDisabled[id] = true;
        }
    }

    const checkReady = setInterval(() => {
        if (playerInstances[id] && playerInstances[id].getDuration && playerInstances[id].getDuration() > 0) {
            clearInterval(checkReady);
            applySync();
            mp4SyncIntervals[id] = setInterval(applySync, 8000);
        }
    }, 500);
    setTimeout(() => clearInterval(checkReady), 10000);
}

function loadPlayer(id) {
    const card = document.querySelector(`.short-card[data-id="${id}"]`);
    if (!card) return;
    const wrap = document.getElementById(`wrap-${id}`);
    if (!wrap) return;
    if (wrap.dataset.loaded === '1') return;
    wrap.dataset.loaded = '1';

    const type = card.dataset.sourceType;
    const url = card.dataset.sourceUrl;
    const syncEpoch = parseInt(card.dataset.syncEpoch, 10) || 0;
    const logo = card.dataset.logo || '';
    const isPaused = card.dataset.isPaused === '1';

    // Если нет источника – показываем заглушку
    if (!type || !url) {
        const msg = isPaused ? 'Трансляция завершена. Владелец канала временно остановил эфир.' : 'Эфир недоступен';
        wrap.innerHTML = `<div style="color:#888;display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif;text-align:center;padding:20px">${msg}</div>`;
        if (logo) {
            const img = document.createElement('img');
            img.className = 'player-logo';
            img.src = logo;
            wrap.appendChild(img);
        }
        return;
    }

    // Для iframe-источников используем прямой iframe
    if (type === 'iframe') {
        let src = url;
        if (src.includes('youtube.com') || src.includes('ok.ru')) {
            if (!src.includes('autoplay=1') && !src.includes('autoplay=0')) {
                src += (src.includes('?') ? '&' : '?') + 'autoplay=1';
            }
        }
        wrap.innerHTML = `<iframe src="${src}" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen style="width:100%;height:100%;border:0;"></iframe>`;
        if (logo) {
            const img = document.createElement('img');
            img.className = 'player-logo';
            img.src = logo;
            wrap.appendChild(img);
        }
        return;
    }

    // Для mp4 и m3u8 — используем PlayerJS
    const containerId = `playerjs-container-${id}`;
    // Удаляем старый контейнер, если есть
    const oldContainer = document.getElementById(containerId);
    if (oldContainer) oldContainer.remove();

    const container = document.createElement('div');
    container.id = containerId;
    container.style.width = '100%';
    container.style.height = '100%';
    wrap.innerHTML = ''; // очищаем
    wrap.appendChild(container);

    // Если есть логотип, добавляем его поверх
    if (logo) {
        const img = document.createElement('img');
        img.className = 'player-logo';
        img.src = logo;
        wrap.appendChild(img);
    }

    const options = {
        id: containerId,
        file: url,
        autoplay: true,
        muted: true,
        poster: logo || '',
        // PlayerJS сам определит тип, но можно явно указать:
        // type: type === 'm3u8' ? 'hls' : 'mp4'
    };

    try {
        const instance = new Playerjs(options);
        playerInstances[id] = instance;

        if (type === 'mp4' && syncEpoch) {
            startMp4Sync(id, syncEpoch);
        } else {
            if (mp4SyncIntervals[id]) clearInterval(mp4SyncIntervals[id]);
        }
    } catch (e) {
        console.error('PlayerJS init error for', id, e);
        wrap.innerHTML = `<div style="color:#f44;display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif">Ошибка плеера</div>`;
        if (logo) {
            const img = document.createElement('img');
            img.className = 'player-logo';
            img.src = logo;
            wrap.appendChild(img);
        }
    }
}

function unloadPlayer(id) {
    destroyPlayer(id);
    const wrap = document.getElementById(`wrap-${id}`);
    if (wrap) {
        // Сохраняем логотип, если он был
        const logo = wrap.querySelector('.player-logo');
        wrap.innerHTML = '';
        if (logo) wrap.appendChild(logo);
        wrap.dataset.loaded = '0';
    }
    if (scheduleIntervals[id]) {
        clearInterval(scheduleIntervals[id]);
        delete scheduleIntervals[id];
    }
}

// Обновление статуса "в эфире" и следующей программы
const scheduleIntervals = {};

function startScheduleCheck(id) {
    if (scheduleIntervals[id]) return;
    scheduleIntervals[id] = setInterval(async () => {
        try {
            const resp = await fetch(`/now_playing.php?channel_id=${id}`);
            const data = await resp.json();
            if (!data.ok) return;
            const source = data.source;
            const card = document.querySelector(`.short-card[data-id="${id}"]`);
            if (!card) return;
            card.dataset.isPaused = data.is_paused ? '1' : '0';
            const badge = card.querySelector('.live-badge');
            if (badge) {
                if (source) {
                    badge.textContent = '● В ЭФИРЕ';
                    badge.className = 'live-badge';
                    badge.dataset.live = '1';
                } else {
                    badge.textContent = data.is_paused ? '⏸ ОСТАНОВЛЕНО' : '○ ОФЛАЙН';
                    badge.className = 'live-badge offline';
                    badge.dataset.live = '0';
                }
            }
            const nextSpan = card.querySelector('.sub span:last-child');
            if (nextSpan && data.next) {
                nextSpan.textContent = `Далее: ${data.next.program_title || data.next.source_name}`;
            }
            const currentSourceId = card.dataset.currentSourceId ? parseInt(card.dataset.currentSourceId) : null;
            if (source && source.id !== currentSourceId) {
                card.dataset.currentSourceId = source.id;
                card.dataset.sourceType = source.type;
                card.dataset.sourceUrl = source.url;
                card.dataset.syncEpoch = source.type === 'mp4' ? (source.sync_epoch || '') : '';
                if (document.getElementById(`wrap-${id}`).dataset.loaded === '1') {
                    unloadPlayer(id);
                    loadPlayer(id);
                }
            }

            // Проверка живости потока для активного m3u8-источника (любого, не только
            // через RTMP-relay) — если пропал, показываем сообщение прямо на плеере,
            // без перезагрузки; вернётся сам, как только вещание возобновится.
            if (source && source.type === 'm3u8') {
                try {
                    const liveResp = await fetch(`/check_stream_live.php?channel_id=${id}`);
                    const liveData = await liveResp.json();
                    const wrap = document.getElementById(`wrap-${id}`);
                    if (liveData.applicable && wrap) {
                        if (!liveData.live && !wrap.dataset.offlineShown) {
                            wrap.innerHTML = `<div style="color:#888;display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif;text-align:center;padding:20px">Стрим сейчас выключен. Включится сам, как только вещание возобновится.</div>`;
                            wrap.dataset.offlineShown = '1';
                            wrap.dataset.loaded = '0';
                        } else if (liveData.live && wrap.dataset.offlineShown) {
                            wrap.dataset.offlineShown = '';
                            unloadPlayer(id);
                            loadPlayer(id);
                        }
                    }
                } catch (e) {}
            }
        } catch(e) {}
    }, 15000);
}

function markAsViewed(id) {
    let viewed = getCookie('viewed_channels') ? getCookie('viewed_channels').split(',') : [];
    if (!viewed.includes(id.toString())) {
        viewed.push(id);
        if (viewed.length > 50) viewed.shift();
        document.cookie = `viewed_channels=${viewed.join(',')}; path=/; max-age=31536000`;
    }
}

function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
}

// ======== INTERSECTION OBSERVER ========
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        const card = entry.target;
        const id = parseInt(card.dataset.id);
        if (entry.isIntersecting) {
            loadPlayer(id);
            markAsViewed(id);
            startScheduleCheck(id);
        } else {
            unloadPlayer(id);
        }
    });
}, { threshold: 0.6 });

document.querySelectorAll('.short-card').forEach(card => observer.observe(card));

// Загружаем первый канал сразу
document.addEventListener('DOMContentLoaded', function() {
    const firstCard = document.querySelector('.short-card');
    if (firstCard) {
        const id = parseInt(firstCard.dataset.id);
        loadPlayer(id);
        markAsViewed(id);
        startScheduleCheck(id);
    }
});
</script>

<script>
(function rememberChannelInterest(){
  const id = <?= (int)$channel['id'] ?>;
  let viewed = (document.cookie.match(/(?:^|; )viewed_channels=([^;]*)/)||[])[1];
  viewed = viewed ? decodeURIComponent(viewed).split(',').filter(Boolean) : [];
  if (!viewed.includes(String(id))) viewed.unshift(String(id));
  document.cookie = 'viewed_channels=' + encodeURIComponent(viewed.slice(0,80).join(',')) + '; path=/; max-age=31536000';
  const words = <?= json_encode(preg_split('/\W+/u', mb_strtolower(($channel['title'] ?? '') . ' ' . ($channel['description'] ?? '')))) ?>;
  words.filter(w => w && w.length > 2).slice(0,12).forEach(w => document.cookie = 'interest_' + md5lite(w) + '=1; path=/; max-age=31536000');
  function md5lite(s){let h=0;for(let i=0;i<s.length;i++){h=((h<<5)-h)+s.charCodeAt(i);h|=0}return Math.abs(h).toString(16)}
})();

// Live comments every 10s for open comment tabs
function pollChannelComments(id) {
  const list = document.getElementById('comment-list-' + id);
  if (!list) return;
  let after = parseInt(list.getAttribute('data-after') || '0', 10) || 0;
  fetch('/comments_poll.php?channel_id=' + id + '&after=' + after, { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d || !d.ok || !d.comments) return;
      d.comments.forEach(c => {
        if (list.querySelector('[data-cid="' + c.id + '"]')) return;
        const div = document.createElement('div');
        div.className = 'comment-item';
        div.setAttribute('data-cid', c.id);
        div.innerHTML = '<b>' + escapeHtml(c.username) + '</b> <span style="color:#888;font-size:0.75rem;">' + escapeHtml(c.created_at) + '</span><br>' + escapeHtml(c.message);
        list.insertBefore(div, list.firstChild);
        after = Math.max(after, c.id);
      });
      if (d.max_id) after = Math.max(after, d.max_id);
      list.setAttribute('data-after', String(after));
    })
    .catch(() => {});
}
setInterval(function () {
  document.querySelectorAll('[id^="comment-list-"]').forEach(function (el) {
    const id = el.id.replace('comment-list-', '');
    if (id && el.offsetParent !== null) pollChannelComments(id);
  });
}, 10000);
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>