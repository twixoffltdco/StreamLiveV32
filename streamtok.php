<?php
/**
 * streamtok.php — TikTok-лента (видео + LIVE-каналы) с ленивой загрузкой плееров
 * Быстрая загрузка: все данные получаются одним запросом, плееры подгружаются при скролле.
 * Только активные каналы (с источником), видео — все одобренные.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$__user = current_user();

// ===== Обработка входа =====
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

$showAuth = !$__user;

// ===== Если пользователь авторизован — готовим контент =====
if ($__user) {
    $tab = $_GET['tab'] ?? 'foryou';
    $items = [];

    try {
        // 1. Получаем все одобренные каналы
        $channels = recommended_channels($__user, null, 60);

        // 2. Получаем все опубликованные видео с данными канала
        $stmt = db()->prepare("
            SELECT v.*, c.id as channel_id, c.slug as channel_slug, c.title as channel_title, c.logo_url as channel_logo
            FROM videos v
            JOIN channels c ON c.id = v.channel_id
            WHERE v.status = 'published'
            ORDER BY v.created_at DESC
        ");
        $stmt->execute();
        $videos = $stmt->fetchAll();

        // 3. Фильтруем каналы: оставляем только с активным источником
        $liveChannels = [];
        foreach ($channels as $ch) {
            $source = resolve_active_source($ch);
            if ($source) {
                $ch['source_type'] = $source['type'];
                $ch['source_url'] = $source['url'];
                $liveChannels[] = $ch;
            }
        }

        // 4. Собираем все в один массив с типом
        $items = [];

        // Видео
        foreach ($videos as $v) {
            $items[] = [
                'type' => 'video',
                'id' => $v['id'],
                'slug' => $v['slug'],
                'title' => $v['title'],
                'description' => $v['description'],
                'thumbnail_url' => $v['thumbnail_url'],
                'embed_url' => $v['embed_url'],
                'platform' => $v['platform'],
                'views_count' => $v['views_count'],
                'likes_count' => $v['likes_count'] ?? 0,
                'comments_count' => $v['comments_count'],
                'created_at' => $v['created_at'],
                'channel_id' => $v['channel_id'],
                'channel_slug' => $v['channel_slug'],
                'channel_title' => $v['channel_title'],
                'channel_logo' => $v['channel_logo'],
            ];
        }

        // Каналы (только LIVE)
        foreach ($liveChannels as $ch) {
            // Проверим, есть ли уже такое же id в items (чтобы избежать дублей, если канал имеет видео)
            // Но у каналов и видео разные id, так что ок.
            $items[] = [
                'type' => 'channel',
                'id' => $ch['id'],
                'slug' => $ch['slug'],
                'title' => $ch['title'],
                'description' => $ch['description'] ?? '',
                'thumbnail_url' => $ch['logo_url'] ?? '',
                'embed_url' => '',
                'platform' => '',
                'views_count' => $ch['views'],
                'likes_count' => 0,
                'comments_count' => 0,
                'created_at' => $ch['created_at'],
                'channel_id' => $ch['id'],
                'channel_slug' => $ch['slug'],
                'channel_title' => $ch['title'],
                'channel_logo' => $ch['logo_url'] ?? '',
                'source_url' => $ch['source_url'],
                'source_type' => $ch['source_type'],
            ];
        }

        // 5. Сортируем по персональному интересу, а не просто по дате
        $viewedTok = array_filter(array_map('intval', explode(',', (string)($_COOKIE['viewed_channels'] ?? ''))));
        usort($items, function($a, $b) use ($viewedTok) {
            $score = function($x) use ($viewedTok) { return log(1 + (int)($x['views_count'] ?? 0)) + (int)($x['likes_count'] ?? 0) * 2 - (in_array((int)($x['channel_id'] ?? 0), $viewedTok, true) ? 8 : 0) + strtotime($x['created_at']) / 86400 / 3650; };
            return $score($b) <=> $score($a);
        });

        // 6. Для каждого элемента определяем, лайкнут ли он и в избранном (для каналов и видео)
        foreach ($items as &$item) {
            $item['liked'] = false;
            $item['favorited'] = false;
            if ($__user) {
                if ($item['type'] === 'video') {
                    // Лайк видео
                    try {
                        $stmt = db()->prepare('SELECT id FROM video_likes WHERE video_id = ? AND user_id = ?');
                        $stmt->execute([$item['id'], $__user['id']]);
                        $item['liked'] = (bool)$stmt->fetch();
                    } catch (Exception $e) {}
                    // Избранное видео
                    try {
                        $stmt = db()->prepare('SELECT id FROM video_favorites WHERE video_id = ? AND user_id = ?');
                        $stmt->execute([$item['id'], $__user['id']]);
                        $item['favorited'] = (bool)$stmt->fetch();
                    } catch (Exception $e) {}
                } else {
                    // Лайк канала
                    try {
                        $stmt = db()->prepare('SELECT id FROM channel_likes WHERE channel_id = ? AND user_id = ?');
                        $stmt->execute([$item['id'], $__user['id']]);
                        $item['liked'] = (bool)$stmt->fetch();
                    } catch (Exception $e) {}
                    // Избранное канала
                    try {
                        $stmt = db()->prepare('SELECT id FROM favorites WHERE channel_id = ? AND user_id = ?');
                        $stmt->execute([$item['id'], $__user['id']]);
                        $item['favorited'] = (bool)$stmt->fetch();
                    } catch (Exception $e) {}
                }
            }

            // Для каналов реальное количество лайков (если не задано)
            if ($item['type'] === 'channel') {
                try {
                    $stmt = db()->prepare('SELECT COUNT(*) FROM channel_likes WHERE channel_id = ?');
                    $stmt->execute([$item['id']]);
                    $item['likes_count'] = (int)$stmt->fetchColumn();
                } catch (Exception $e) {
                    $item['likes_count'] = 0;
                }
            } else {
                // Для видео, если не задано, тоже посчитаем
                if (!isset($item['likes_count']) || $item['likes_count'] === 0) {
                    try {
                        $stmt = db()->prepare('SELECT COUNT(*) FROM video_likes WHERE video_id = ?');
                        $stmt->execute([$item['id']]);
                        $item['likes_count'] = (int)$stmt->fetchColumn();
                    } catch (Exception $e) {
                        $item['likes_count'] = 0;
                    }
                }
            }
        }
        unset($item);

        // 7. Фильтр по вкладкам
        if ($tab === 'subscriptions') {
            // Оставляем только избранное (каналы и видео)
            $items = array_filter($items, function($item) {
                return $item['favorited'] === true;
            });
            // Переиндексируем
            $items = array_values($items);
        } elseif ($tab === 'live') {
            // Оставляем только каналы (все они LIVE, т.к. мы уже отфильтровали)
            $items = array_filter($items, function($item) {
                return $item['type'] === 'channel';
            });
            $items = array_values($items);
        } // для foryou — все

        $pageTitle = 'StreamTok';
    } catch (Exception $e) {
        $items = [];
        $dbError = $e->getMessage();
    }
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
        .auth-input { width:100%; padding:15px; border:none; border-radius:12px; font-size:16px; margin-bottom:15px; background:rgba(255,255,255,0.9); color:#000; }
        .auth-btn { width:100%; padding:15px; border:none; border-radius:12px; font-size:16px; font-weight:600; background:#fff; color:#667eea; cursor:pointer; }
        .auth-error { color:#ff4444; margin-bottom:15px; text-align:center; }
        .auth-link { color:#fff; margin-top:15px; text-align:center; }
        .auth-link a { color:#fff; text-decoration:underline; }
        .app-container { height:100vh; display:flex; flex-direction:column; }
        .content-area { flex:1; overflow-y:scroll; scroll-snap-type:y mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
        .content-area::-webkit-scrollbar { display:none; }
        .video-item { height:100vh; width:100%; position:relative; scroll-snap-align:start; background:var(--bg); display:flex; align-items:center; justify-content:center; overflow:hidden; }
        .player-container { width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:#000; }
        .player-container iframe, .player-container video, .player-container img { width:100%; height:100%; border:none; object-fit:contain; }
        .video-overlay { position:absolute; bottom:80px; left:0; right:0; padding:20px; background:linear-gradient(transparent, rgba(0,0,0,0.7)); z-index:10; pointer-events:none; }
        .video-title { font-size:16px; font-weight:600; color:#fff; }
        .video-title a { color:#fff; text-decoration:none; }
        .video-title .channel-name { opacity:0.7; font-weight:400; font-size:14px; }
        .side-actions { position:absolute; right:10px; bottom:150px; z-index:11; display:flex; flex-direction:column; gap:20px; }
        .action-btn { background:rgba(255,255,255,0.15); border:none; width:50px; height:50px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#fff; font-size:20px; backdrop-filter:blur(10px); flex-direction:column; }
        .action-btn span { font-size:11px; margin-top:2px; }
        .action-btn.liked { color:#ff2d55; background:rgba(255,45,85,0.3); }
        .action-btn.favorited { color:#ffcc00; background:rgba(255,204,0,0.3); }
        .bottom-nav { height:60px; background:var(--bg); border-top:1px solid var(--border); display:flex; justify-content:space-around; align-items:center; z-index:20; flex-shrink:0; }
        .nav-btn { background:none; border:none; color:var(--text); font-size:24px; cursor:pointer; padding:10px 20px; opacity:0.6; transition:all 0.3s; }
        .nav-btn.active { opacity:1; color:var(--accent); }
        .tab-nav { display:flex; gap:30px; padding:15px 20px; background:var(--bg); border-bottom:1px solid var(--border); justify-content:center; flex-shrink:0; }
        .tab-btn { background:none; border:none; color:var(--text); font-size:16px; font-weight:600; opacity:0.6; cursor:pointer; transition:all 0.3s; }
        .tab-btn.active { opacity:1; }
        .live-badge { background:var(--red); padding:2px 8px; border-radius:4px; font-size:12px; margin-left:8px; animation:pulse 2s infinite; }
        .badge-live { background:#ff2d55; padding:2px 8px; border-radius:4px; font-size:11px; margin-left:6px; }
        .badge-video { background:#00f2ea; padding:2px 8px; border-radius:4px; font-size:11px; margin-left:6px; color:#000; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.6; } }
        .empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100vh; color:#666; padding:20px; text-align:center; font-size:18px; }
        .empty-state p:first-child { font-size:18px; margin-bottom:10px; }
        .empty-state p:last-child { font-size:14px; opacity:0.7; }
        .loading-placeholder { color:#555; font-size:16px; }
        @media (max-width:768px) { .side-actions { right:8px; bottom:100px; gap:12px; } .action-btn { width:44px; height:44px; font-size:18px; } .video-overlay { bottom:60px; padding:15px; } .video-title { font-size:14px; } .tab-nav { gap:15px; padding:10px; } .tab-btn { font-size:14px; } }
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
            <?php elseif (empty($items)): ?>
                <div class="empty-state">
                    <?php if ($tab === 'subscriptions'): ?>
                        <p>💔 Нет подписок</p><p>Подпишитесь на каналы или видео</p>
                    <?php elseif ($tab === 'live'): ?>
                        <p>📡 Нет активных прямых эфиров</p>
                    <?php else: ?>
                        <p>📺 Контента пока нет</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($items as $idx => $item): ?>
                    <div class="video-item" data-type="<?= $item['type'] ?>" data-id="<?= $item['id'] ?>" data-slug="<?= $item['slug'] ?>" data-embed-url="<?= htmlspecialchars($item['embed_url']) ?>" data-platform="<?= htmlspecialchars($item['platform']) ?>" data-source-url="<?= htmlspecialchars($item['source_url'] ?? '') ?>">
                        <div class="player-container" id="player-<?= $item['type'] . '-' . $item['id'] ?>">
                            <div class="loading-placeholder">⏳ Загрузка...</div>
                        </div>
                        <div class="video-overlay">
                            <div class="video-title">
                                <?php if ($item['type'] === 'video'): ?>
                                    <a href="/video.php?slug=<?= urlencode($item['slug']) ?>" target="_blank"><?= htmlspecialchars($item['title']) ?></a>
                                    <span class="channel-name">— <a href="/channel.php?slug=<?= urlencode($item['channel_slug']) ?>" target="_blank" style="color:#fff;opacity:0.7;"><?= htmlspecialchars($item['channel_title']) ?></a></span>
                                    <span class="badge-video">Видео</span>
                                <?php else: ?>
                                    <a href="/channel.php?slug=<?= urlencode($item['slug']) ?>" target="_blank"><?= htmlspecialchars($item['title']) ?></a>
                                    <span class="channel-name">— канал</span>
                                    <span class="badge-live">🔴 LIVE</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="side-actions">
                            <button class="action-btn <?= $item['liked'] ? 'liked' : '' ?>" onclick="toggleLike(this, '<?= $item['type'] ?>', <?= $item['id'] ?>)">
                                ♥ <span><?= (int)$item['likes_count'] ?></span>
                            </button>
                            <button class="action-btn <?= $item['favorited'] ? 'favorited' : '' ?>" onclick="toggleFavorite(this, '<?= $item['type'] ?>', <?= $item['id'] ?>)">
                                <?= $item['favorited'] ? '★' : '☆' ?>
                            </button>
                            <button class="action-btn" onclick="window.open('<?= $item['type'] === 'video' ? '/video.php?slug=' . urlencode($item['slug']) : '/channel.php?slug=' . urlencode($item['slug']) ?>', '_blank')">🔗</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="bottom-nav">
            <button class="nav-btn active" onclick="changeTab('foryou')">🏠</button>
            <button class="nav-btn" onclick="location.href='/favorites.php'">❤️</button>
            <button class="nav-btn" onclick="location.href='/dashboard.php'">👤</button>
        </div>
    </div>
<?php endif; ?>

<script>
    const currentUserId = <?= $__user ? (int)$__user['id'] : 'null' ?>;

    function changeTab(tab) {
        location.href = '/streamtok.php?tab=' + tab;
    }

    // ===== ЛАЙКИ И ИЗБРАННОЕ =====
    async function toggleLike(btn, type, id) {
        if (!currentUserId) { location.href = '/auth/login.php'; return; }
        const url = type === 'video' ? '/video_like.php' : '/channel_like.php';
        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ video_id: id, channel_id: id })
            });
            const data = await resp.json();
            if (data.ok) {
                btn.classList.toggle('liked', data.liked);
                const span = btn.querySelector('span');
                if (span) span.textContent = data.count || 0;
            }
        } catch (e) { console.error(e); }
    }

    async function toggleFavorite(btn, type, id) {
        if (!currentUserId) { location.href = '/auth/login.php'; return; }
        const url = type === 'video' ? '/video_favorite.php' : '/channel_favorite.php';
        try {
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ video_id: id, channel_id: id })
            });
            const data = await resp.json();
            if (data.ok) {
                btn.classList.toggle('favorited', data.favorited);
                btn.textContent = data.favorited ? '★' : '☆';
            }
        } catch (e) { console.error(e); }
    }

    // ===== ЛЕНИВАЯ ЗАГРУЗКА ПЛЕЕРОВ (Intersection Observer) =====
    let loadedPlayers = new Set();

    function loadPlayer(element) {
        const container = element.querySelector('.player-container');
        if (!container) return;
        const id = container.id;
        if (loadedPlayers.has(id)) return;
        loadedPlayers.add(id);

        const type = element.dataset.type;
        const slug = element.dataset.slug;
        const embedUrl = element.dataset.embedUrl;
        const platform = element.dataset.platform;
        const sourceUrl = element.dataset.sourceUrl;

        let html = '';
        if (type === 'video') {
            if (platform === 'mp4') {
                html = `<video controls autoplay muted playsinline loop><source src="${embedUrl}" type="video/mp4"></video>`;
            } else if (platform === 'm3u8') {
                const videoId = 'hls-' + id;
                html = `<video id="${videoId}" controls autoplay muted playsinline loop></video>
                        <script>
                            (function() {
                                var src = "${embedUrl}";
                                var v = document.getElementById("${videoId}");
                                if (window.Hls && Hls.isSupported()) {
                                    var hls = new Hls();
                                    hls.loadSource(src);
                                    hls.attachMedia(v);
                                } else {
                                    v.src = src;
                                }
                                v.play().catch(() => {});
                            })();
                        <\/script>`;
            } else if (platform === 'youtube' || platform === 'tiktok') {
                html = `<iframe src="${embedUrl}" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
            } else {
                html = `<iframe src="${embedUrl}" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
            }
        } else {
            // Канал
            if (sourceUrl) {
                html = `<iframe src="/embed.php?slug=${slug}&autoplay=1&mute=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
            } else {
                html = `<div style="color:#555;font-size:18px;display:flex;align-items:center;justify-content:center;height:100%;">Сейчас нет эфира</div>`;
            }
        }

        container.innerHTML = html;
    }

    // Настройка Intersection Observer
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                loadPlayer(entry.target);
            }
        });
    }, {
        threshold: 0.1, // загружать, когда 10% элемента видно
        rootMargin: '100px' // загружать заранее
    });

    // Наблюдаем за всеми video-item
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.video-item').forEach(item => {
            observer.observe(item);
        });
        // Загружаем первый сразу
        const first = document.querySelector('.video-item');
        if (first) loadPlayer(first);
    });
</script>
</body>
</html>