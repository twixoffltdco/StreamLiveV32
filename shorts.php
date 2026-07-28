<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/headershorts.php';

// Персональная лента: учитываем просмотры из cookies, лайки/избранное авторизованного пользователя и интересы из названий.
$__user = current_user();
$channels = recommended_channels($__user, null, 20);

$pageTitle = 'Shorts';
?>
<style>
  body { overflow: hidden; background: #000; margin: 0; padding: 0; }
  .navbar { position: fixed; top: 0; left: 0; right: 0; z-index: 1000; background: rgba(0,0,0,0.8); }
  .shorts-container {
    height: 100vh;
    scroll-snap-type: y mandatory;
    overflow-y: scroll;
    padding-top: 60px;
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
  }
  .short-video-wrap iframe, .short-video-wrap video {
    width: 100%;
    height: 100%;
    border: none;
    object-fit: contain;
  }
  .short-overlay {
    position: absolute;
    bottom: 20px;
    left: 20px;
    right: 80px;
    color: #fff;
    text-shadow: 0 2px 4px rgba(0,0,0,0.8);
    pointer-events: none;
  }
  .short-overlay h2 { margin: 0; font-size: 1.2rem; }
  .short-actions {
    position: absolute;
    right: 15px;
    bottom: 100px;
    display: flex;
    flex-direction: column;
    gap: 20px;
    align-items: center;
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
  }
  .chat-toggle-btn { background: var(--primary); }
  
  .short-chat-panel {
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
  .short-chat-panel.active { right: 0; }
  .chat-messages { flex: 1; overflow-y: auto; padding: 15px; color: #fff; font-size: 0.9rem; }
  .chat-msg { margin-bottom: 8px; line-height: 1.4; word-wrap: break-word; }
  .chat-msg b { color: var(--primary); }
  .chat-input-row { padding: 10px; display: flex; gap: 8px; border-top: 1px solid #333; }
  .chat-input-row input { flex: 1; background: #222; border: 1px solid #444; color: #fff; padding: 8px; border-radius: 4px; font-family: inherit; }
  .chat-input-row button { padding: 8px 12px; }
  
  @media (max-width: 768px) {
    .short-chat-panel { width: 100%; right: -100%; }
    .short-overlay { bottom: 80px; }
  }
</style>

<div class="shorts-container" id="shorts-container">
  <?php foreach ($channels as $index => $c): ?>
    <div class="short-card" data-id="<?= $c['id'] ?>" data-slug="<?= $c['slug'] ?>">
      <div class="short-video-wrap">
        <div id="player-<?= $c['id'] ?>" class="player-placeholder" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#555">
            Загрузка...
        </div>
      </div>
      <div class="short-overlay">
        <h2><?= e($c['title']) ?></h2>
        <p><?= (int)$c['views'] ?> просмотров</p>
      </div>
      <div class="short-actions">
        <button type="button" class="action-btn short-like-btn <?php if ($__user) { $stmt = db()->prepare('SELECT id FROM short_likes WHERE channel_id = ? AND user_id = ?'); $stmt->execute([$c['id'], $__user['id']]); echo $stmt->fetch() ? 'liked' : ''; } ?>" data-id="<?= (int)$c['id'] ?>" onclick="toggleShortLike(this)">
          ♥ <span><?php $lc = db()->prepare('SELECT COUNT(*) FROM short_likes WHERE channel_id = ?'); $lc->execute([$c['id']]); echo (int)$lc->fetchColumn(); ?></span>
        </button>
        <a href="/channel.php?slug=<?= $c['slug'] ?>" class="action-btn">🔗</a>
      </div>
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
            <a href="/auth/login.php" style="color:var(--primary);font-size:0.8rem;text-align:center;width:100%">Войдите, чтобы писать</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script>
const activeChats = new Set();
const lastMessageIds = {};

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
        if (!resp.ok) {
            setTimeout(() => pollChat(id), 3000);
            return;
        }
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Poll parse error:', text);
            setTimeout(() => pollChat(id), 3000);
            return;
        }
        const box = document.getElementById(`chat-messages-${id}`);
        if (!box) return;
        
        (data.messages || []).forEach(m => {
            const row = document.createElement('div');
            row.className = 'chat-msg';
            row.innerHTML = `<b>${escapeHtml(m.username)}:</b> ${escapeHtml(m.message)}`;
            box.appendChild(row);
            box.scrollTop = box.scrollHeight;
            lastMessageIds[id] = Math.max(lastMessageIds[id] || 0, m.id);
        });
        (data.deleted || []).forEach(msgId => {
            const el = box.querySelector(`.chat-msg[data-id="${msgId}"]`);
            if (el) el.remove();
        });
    } catch (e) {
        console.error('Poll error:', e);
    }
    setTimeout(() => pollChat(id), 2000);
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
        // Пробуем JSON
        let resp = await fetch('/chat_send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({ channel_id: id, message: message })
        });
        
        let text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            // Пробуем POST форму
            console.log('JSON failed, trying POST...');
            const formData = new FormData();
            formData.append('channel_id', id);
            formData.append('message', message);
            
            resp = await fetch('/chat_send', {
                method: 'POST',
                body: formData
            });
            
            text = await resp.text();
            try {
                data = JSON.parse(text);
            } catch (e2) {
                console.error('Response:', text);
                alert('Ошибка сервера');
                input.disabled = false;
                return;
            }
        }
        
        if (data.ok) {
            input.value = '';
        } else {
            alert(data.error || 'Ошибка при отправке');
        }
        input.disabled = false;
    } catch (e) {
        console.error('Fetch error:', e);
        alert('Ошибка сети');
        input.disabled = false;
    }
}

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const id = entry.target.dataset.id;
            const slug = entry.target.dataset.slug;
            loadPlayer(id, slug);
            markAsViewed(id);
        } else {
            const id = entry.target.dataset.id;
            unloadPlayer(id);
        }
    });
}, { threshold: 0.6 });

document.querySelectorAll('.short-card').forEach(card => observer.observe(card));

async function loadPlayer(id, slug) {
    const container = document.getElementById(`player-${id}`);
    if (container.dataset.loaded === '1') return;
    // Раньше сюда вставлялся сырой HTML через fetch+innerHTML — но <script> внутри
    // него браузер НЕ выполняет при таком способе вставки, из-за чего HLS-плеер,
    // логотип поверх плеера и автопереключение по расписанию внутри shorts не работали.
    // iframe с тем же embed.php выполняется как полноценная страница — всё работает как надо.
    container.innerHTML = `<iframe src="/embed.php?slug=${encodeURIComponent(slug)}" style="width:100%;height:100%;border:0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
    container.dataset.loaded = '1';
}

function unloadPlayer(id) {
    const container = document.getElementById(`player-${id}`);
    container.innerHTML = 'Загрузка...';
    container.dataset.loaded = '0';
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

async function toggleShortLike(btn) {
    <?php if (!$__user): ?>
    location.href = '/auth/login.php';
    return;
    <?php endif; ?>
    const id = btn.dataset.id;
    try {
        const resp = await fetch('/short_like', {
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
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
