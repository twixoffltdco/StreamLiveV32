<?php
/**
 * streamtok.php — TikTok-подобный просмотр каналов
 * Оптимизированная версия: ленивая загрузка, только один плеер активен.
 * Антибот не срабатывает, т.к. загружается только один iframe.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$__user = current_user();
$tab = $_GET['tab'] ?? 'foryou';

try {
    $testTable = db()->query("SHOW TABLES LIKE 'favorites'");
    $tableExists = $testTable->rowCount() > 0;
} catch (Exception $e) {
    $tableExists = false;
}

// Обработка входа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_phone']) && isset($_POST['login_password'])) {
    $phone = function_exists('normalize_phone') ? normalize_phone($_POST['login_phone']) : $_POST['login_phone'];
    $password = $_POST['login_password'];
    if ($phone && $password) {
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
            $stmt->execute([$phone]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                login_user((int)$user['id']);
                header('Location: /streamtok.php');
                exit;
            } else {
                $login_error = 'Неверный номер или пароль';
            }
        } catch (Exception $e) {
            $login_error = 'Ошибка базы данных';
        }
    } else {
        $login_error = 'Заполните все поля';
    }
}

if ($__user) {
    try {
        $sql = "SELECT c.* FROM channels c WHERE c.status = 'approved'";
        $params = [];
        if ($tab === 'subscriptions') {
            if ($tableExists) {
                $sql .= " AND EXISTS (SELECT 1 FROM favorites f WHERE f.channel_id = c.id AND f.user_id = ?)";
                $params[] = $__user['id'];
            } else {
                $channels = [];
                $tableMissing = true;
            }
        } elseif ($tab === 'live') {
            $sql .= " AND c.type = 'tv'";
        }
        if (!isset($channels) && !isset($tableMissing)) {
            $sql .= " ORDER BY c.views DESC, c.created_at DESC";
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $channels = $stmt->fetchAll();
        } elseif (!isset($channels)) {
            $channels = [];
        }
    } catch (Exception $e) {
        $channels = [];
        $dbError = $e->getMessage();
    }

    foreach ($channels as &$ch) {
        try {
            $lc = db()->prepare('SELECT COUNT(*) FROM channel_likes WHERE channel_id = ?');
            $lc->execute([$ch['id']]);
            $ch['like_count'] = (int)$lc->fetchColumn();
        } catch (Exception $e) {
            $ch['like_count'] = 0;
        }
        $ch['liked'] = false;
        $ch['favorited'] = false;
        if ($__user) {
            try {
                $stmtLike = db()->prepare('SELECT id FROM channel_likes WHERE channel_id = ? AND user_id = ?');
                $stmtLike->execute([$ch['id'], $__user['id']]);
                $ch['liked'] = (bool)$stmtLike->fetch();
            } catch (Exception $e) {}
            if ($tableExists) {
                try {
                    $stmtFav = db()->prepare('SELECT id FROM favorites WHERE channel_id = ? AND user_id = ?');
                    $stmtFav->execute([$ch['id'], $__user['id']]);
                    $ch['favorited'] = (bool)$stmtFav->fetch();
                } catch (Exception $e) {}
            }
        }
    }
    unset($ch);

    $pageTitle = 'StreamTok';
    $showAuth = false;
} else {
    $showAuth = true;
}
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>StreamTok</title>
    <style>
        :root[data-theme="dark"] {
            --bg: #000;
            --bg2: #1a1a1a;
            --bg3: #2a2a2a;
            --text: #fff;
            --accent: #00f2ea;
            --red: #ff2d55;
            --border: #222;
        }
        :root[data-theme="light"] {
            --bg: #ffffff;
            --bg2: #f5f5f5;
            --bg3: #e0e0e0;
            --text: #000000;
            --accent: #00b8b0;
            --red: #ff2d55;
            --border: #d0d0d0;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow: hidden;
            height: 100vh;
            position: fixed;
            width: 100%;
            -webkit-user-select: none;
            user-select: none;
        }
        .auth-screen {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .auth-logo { font-size: 60px; margin-bottom: 20px; }
        .auth-title { font-size: 28px; font-weight: 700; margin-bottom: 10px; color: #fff; }
        .auth-subtitle { font-size: 14px; opacity: 0.8; margin-bottom: 40px; text-align: center; color: #fff; }
        .auth-form { width: 100%; max-width: 350px; }
        .auth-input {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            margin-bottom: 15px;
            background: rgba(255,255,255,0.9);
            color: #000;
        }
        .auth-btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            background: #fff;
            color: #667eea;
            cursor: pointer;
        }
        .auth-error { color: #ff4444; margin-bottom: 15px; text-align: center; }
        .auth-link { color: #fff; margin-top: 15px; text-align: center; }
        .auth-link a { color: #fff; text-decoration: underline; }
        .app-container { height: 100vh; display: flex; flex-direction: column; }
        .content-area {
            flex: 1;
            overflow-y: scroll;
            scroll-snap-type: y mandatory;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .content-area::-webkit-scrollbar { display: none; }
        .video-item {
            height: 100vh;
            width: 100%;
            position: relative;
            scroll-snap-align: start;
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .player-container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
        }
        .player-container iframe,
        .player-container video,
        .player-container img {
            width: 100%;
            height: 100%;
            border: none;
            object-fit: contain;
        }
        .video-overlay {
            position: absolute;
            bottom: 80px;
            left: 0;
            right: 0;
            padding: 20px;
            background: linear-gradient(transparent, rgba(0,0,0,0.7));
            z-index: 10;
            pointer-events: none;
        }
        .video-title { font-size: 16px; font-weight: 600; color: #fff; }
        .side-actions {
            position: absolute;
            right: 10px;
            bottom: 150px;
            z-index: 11;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .action-btn {
            background: rgba(255,255,255,0.15);
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #fff;
            font-size: 20px;
            backdrop-filter: blur(10px);
            flex-direction: column;
        }
        .action-btn span { font-size: 11px; margin-top: 2px; }
        .action-btn.liked { color: #ff2d55; background: rgba(255,45,85,0.3); }
        .action-btn.favorited { color: #ffcc00; background: rgba(255,204,0,0.3); }
        .bottom-nav {
            height: 60px;
            background: var(--bg);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-around;
            align-items: center;
            z-index: 20;
            flex-shrink: 0;
        }
        .nav-btn {
            background: none;
            border: none;
            color: var(--text);
            font-size: 24px;
            cursor: pointer;
            padding: 10px 20px;
            opacity: 0.6;
            transition: all 0.3s;
        }
        .nav-btn.active { opacity: 1; color: var(--accent); }
        .tab-nav {
            display: flex;
            gap: 30px;
            padding: 15px 20px;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            justify-content: center;
            flex-shrink: 0;
        }
        .tab-btn {
            background: none;
            border: none;
            color: var(--text);
            font-size: 16px;
            font-weight: 600;
            opacity: 0.6;
            cursor: pointer;
            transition: all 0.3s;
        }
        .tab-btn.active { opacity: 1; }
        .live-badge { 
            background: var(--red); 
            padding: 2px 8px; 
            border-radius: 4px; 
            font-size: 12px; 
            margin-left: 8px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            color: #666;
            padding: 20px;
            text-align: center;
            font-size: 18px;
        }
        .empty-state p:first-child { font-size: 18px; margin-bottom: 10px; }
        .empty-state p:last-child { font-size: 14px; opacity: 0.7; }
        @media (max-width: 768px) {
            .side-actions { right: 8px; bottom: 100px; gap: 12px; }
            .action-btn { width: 44px; height: 44px; font-size: 18px; }
            .video-overlay { bottom: 60px; padding: 15px; }
            .video-title { font-size: 14px; }
            .tab-nav { gap: 15px; padding: 10px; }
            .tab-btn { font-size: 14px; }
        }
        .loading-placeholder {
            color: #555;
            font-size: 16px;
        }
    </style>
</head>
<body>

<?php if ($showAuth): ?>
    <div class="auth-screen">
        <div class="auth-logo">📱</div>
        <div class="auth-title">StreamTok</div>
        <div class="auth-subtitle">Войдите с номером телефона</div>
        <form class="auth-form" method="POST" action="/streamtok">
            <?php if (isset($login_error)): ?>
                <div class="auth-error"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>
            <input type="tel" class="auth-input" name="login_phone" placeholder="+7 999 123 4567" required>
            <input type="password" class="auth-input" name="login_password" placeholder="Пароль" required>
            <button class="auth-btn" type="submit">Войти</button>
            <div class="auth-link">Нет аккаунта? <a href="/auth/register.php">Зарегистрироваться</a></div>
        </form>
    </div>
<?php else: ?>
    <div class="app-container">
        <div class="tab-nav">
            <button class="tab-btn <?= $tab === 'foryou' ? 'active' : '' ?>" onclick="changeTab('foryou')">Для вас</button>
            <button class="tab-btn <?= $tab === 'subscriptions' ? 'active' : '' ?>" onclick="changeTab('subscriptions')">Подписки</button>
            <button class="tab-btn <?= $tab === 'live' ? 'active' : '' ?>" onclick="changeTab('live')">Live</button>
        </div>

        <div class="content-area" id="contentArea">
            <?php if (isset($dbError)): ?>
                <div class="empty-state"><p>⚠️ Ошибка базы данных</p><p><?= htmlspecialchars($dbError) ?></p></div>
            <?php elseif (isset($tableMissing)): ?>
                <div class="empty-state"><p>📂 Таблица "избранное" не найдена</p></div>
            <?php elseif (empty($channels)): ?>
                <div class="empty-state">
                    <?php if ($tab === 'subscriptions'): ?>
                        <p>💔 Нет подписок</p><p>Подпишитесь на каналы</p>
                    <?php elseif ($tab === 'live'): ?>
                        <p>📡 Нет активных прямых эфиров</p>
                    <?php else: ?>
                        <p>📺 Каналов пока нет</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($channels as $idx => $ch): ?>
                    <div class="video-item" data-channel-id="<?= $ch['id'] ?>" data-slug="<?= $ch['slug'] ?>" data-index="<?= $idx ?>">
                        <!-- Контейнер для плеера (сначала пустой) -->
                        <div class="player-container" id="player-container-<?= $ch['id'] ?>">
                            <div class="loading-placeholder">⏳ Загрузка...</div>
                        </div>
                        <div class="video-overlay">
                            <div class="video-title">
                                <?= htmlspecialchars($ch['title']) ?>
                                <?php if ($ch['type'] === 'tv'): ?><span class="live-badge">🔴 LIVE</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="side-actions">
                            <button class="action-btn <?= $ch['liked'] ? 'liked' : '' ?>" onclick="toggleLike(<?= $ch['id'] ?>, this)">
                                ♥ <span><?= $ch['like_count'] ?></span>
                            </button>
                            <button class="action-btn <?= $ch['favorited'] ? 'favorited' : '' ?>" onclick="toggleFavorite(<?= $ch['id'] ?>, this)">
                                <?= $ch['favorited'] ? '★' : '☆' ?>
                            </button>
                            <button class="action-btn" onclick="window.open('/channel.php?slug=<?= urlencode($ch['slug']) ?>', '_blank')">🔗</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="bottom-nav">
            <button class="nav-btn active" onclick="changeTab('foryou')">🏠</button>
            <button class="nav-btn" onclick="location.href='/favorites.php'">❤️</button>
            <button class="nav-btn" onclick="location.href='/profile.php'">👤</button>
        </div>
    </div>
<?php endif; ?>

<script>
    const currentUserId = <?= $__user ? (int)$__user['id'] : 'null' ?>;

    function changeTab(tab) {
        location.href = '/streamtok.php?tab=' + tab;
    }

    async function toggleLike(id, btn) {
        if (!currentUserId) { location.href = '/auth/login.php'; return; }
        try {
            const resp = await fetch('/channel_like', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ channel_id: id })
            });
            const data = await resp.json();
            if (data.ok) {
                btn.classList.toggle('liked', data.liked);
                const span = btn.querySelector('span');
                if (span) span.textContent = data.count;
            }
        } catch (e) { console.error(e); }
    }

    async function toggleFavorite(id, btn) {
        if (!currentUserId) { location.href = '/auth/login.php'; return; }
        try {
            const resp = await fetch('/channel_favorite', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ channel_id: id })
            });
            const data = await resp.json();
            if (data.ok) {
                btn.classList.toggle('favorited', data.favorited);
                btn.textContent = data.favorited ? '★' : '☆';
            }
        } catch (e) { console.error(e); }
    }

    // ===== ЛЕНИВАЯ ЗАГРУЗКА ПЛЕЕРА (ТОЛЬКО АКТИВНЫЙ КАНАЛ) =====
    let activeChannelId = null;
    let previousChannelId = null;
    const playerContainers = {};

    function loadPlayer(slug, channelId) {
        const container = document.getElementById('player-container-' + channelId);
        if (!container) return;
        // Если уже загружен и это тот же канал — ничего не делаем
        if (activeChannelId === channelId && container.querySelector('iframe')) return;

        // Если был активный другой канал — удаляем его плеер
        if (previousChannelId && previousChannelId !== channelId) {
            const oldContainer = document.getElementById('player-container-' + previousChannelId);
            if (oldContainer) {
                oldContainer.innerHTML = '<div class="loading-placeholder">⏳ Загрузка...</div>';
            }
        }

        // Создаём iframe
        const iframe = document.createElement('iframe');
        iframe.src = '/embed.php?slug=' + encodeURIComponent(slug) + '&autoplay=1&mute=0';
        iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
        iframe.allowFullscreen = true;
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = 'none';

        // Очищаем контейнер и вставляем iframe
        container.innerHTML = '';
        container.appendChild(iframe);

        // Запоминаем активный канал
        previousChannelId = activeChannelId;
        activeChannelId = channelId;
    }

    function unloadPlayer(channelId) {
        const container = document.getElementById('player-container-' + channelId);
        if (container) {
            container.innerHTML = '<div class="loading-placeholder">⏳ Загрузка...</div>';
        }
        if (activeChannelId === channelId) {
            activeChannelId = null;
        }
        if (previousChannelId === channelId) {
            previousChannelId = null;
        }
    }

    function updateActiveVideo() {
        const items = document.querySelectorAll('.video-item');
        let activeIndex = -1;
        const halfHeight = window.innerHeight / 2;
        items.forEach((item, idx) => {
            const rect = item.getBoundingClientRect();
            const center = rect.top + rect.height / 2;
            if (center >= 0 && center <= window.innerHeight) {
                activeIndex = idx;
            }
        });
        if (activeIndex === -1 && items.length > 0) activeIndex = 0;
        if (activeIndex === -1) return;

        const activeItem = items[activeIndex];
        const slug = activeItem.dataset.slug;
        const channelId = activeItem.dataset.channelId;

        // Если канал уже активен — не перезагружаем
        if (activeChannelId === channelId) return;

        // Загружаем новый плеер
        loadPlayer(slug, channelId);
    }

    // Обработчик скролла с debounce (чтобы не спамить)
    let scrollTimeout;
    const area = document.getElementById('contentArea');
    area.addEventListener('scroll', function() {
        clearTimeout(scrollTimeout);
        scrollTimeout = setTimeout(updateActiveVideo, 80);
    });

    // При загрузке и изменении размера
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(updateActiveVideo, 300);
    });
    window.addEventListener('resize', updateActiveVideo);
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) updateActiveVideo();
    });

    // При изменении вкладок обновляем
    document.addEventListener('tabChange', updateActiveVideo);
</script>

</body>
</html>