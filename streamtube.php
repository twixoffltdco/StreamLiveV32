<?php
/**
 * streamtube.php — Главная страница в стиле YouTube 2026
 * Объединяет каталог каналов, плеер с расписанием и рекомендации.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/recommendations.php';

// ---- Получение данных ----
$slug = $_GET['slug'] ?? '';
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? null;
$type = in_array($type, ['tv', 'radio']) ? $type : null;

$user = current_user();
$csrf_token = csrf_token();

// ---- Обработка канала (если выбран slug) ----
$channel = null;
$isLive = false;
$activeSource = null;
$schedule = [];
$nowPlaying = null;

if ($slug) {
    $stmt = db()->prepare('SELECT * FROM channels WHERE slug = ? AND status = "approved"');
    $stmt->execute([$slug]);
    $channel = $stmt->fetch();

    if ($channel) {
        // Увеличиваем счётчик просмотров
        db()->prepare('UPDATE channels SET views = views + 1 WHERE id = ?')->execute([$channel['id']]);

        // Получаем активный источник и статус LIVE
        $activeSource = resolve_active_source($channel);
        $isLive = $activeSource !== null;

        // Получаем расписание на неделю
        $stmt = db()->prepare(
            "SELECT sch.*, s.name as source_name 
             FROM schedule sch 
             JOIN sources s ON s.id = sch.source_id 
             WHERE channel_id = ? 
             ORDER BY day_of_week, start_time"
        );
        $stmt->execute([$channel['id']]);
        $schedule = $stmt->fetchAll();

        // Получаем текущую программу (сейчас в эфире)
        $nowPlaying = find_now_playing($channel['id']);
    }
}

// ---- Получение списка каналов для сайдбара и каталога ----
$stmt = db()->prepare('SELECT id, slug, title, logo_url, type FROM channels WHERE status = "approved" ORDER BY views DESC LIMIT 50');
$stmt->execute();
$channelsSidebar = $stmt->fetchAll();

// ---- Персональные рекомендации (для главной страницы) ----
$recommendations = [];
if (!$slug) {
    // Если мы на главной, получаем рекомендации на основе истории просмотров
    $recommendations = get_recommended_videos(null, 12);
}

// ---- SEO ----
$pageTitle = $channel ? ($channel['seo_title'] ?: $channel['title']) : 'StreamTube — Смотрите онлайн';
$seoDescription = $channel ? ($channel['seo_description'] ?: $channel['description']) : 'Смотрите прямые эфиры телеканалов и радио. Расписание программ, рекомендации и общение в чате.';

// ---- Подключение шапки сайта ----
require_once __DIR__ . '/includes/header.php';
?>

<div class="streamtube-wrapper">
    <div class="streamtube-container">
        <!-- БОКОВАЯ ПАНЕЛЬ (каталог каналов) -->
        <aside class="streamtube-sidebar">
            <div class="sidebar-header">
                <span class="sidebar-title">📺 Каналы</span>
                <span class="sidebar-count"><?= count($channelsSidebar) ?></span>
            </div>

            <div class="sidebar-filters">
                <button class="filter-pill <?= !$type ? 'active' : '' ?>" onclick="location.href='/streamtube.php'">Все</button>
                <button class="filter-pill <?= $type === 'tv' ? 'active' : '' ?>" onclick="location.href='/streamtube.php?type=tv'">ТВ</button>
                <button class="filter-pill <?= $type === 'radio' ? 'active' : '' ?>" onclick="location.href='/streamtube.php?type=radio'">Радио</button>
            </div>

            <div class="sidebar-list">
                <?php foreach ($channelsSidebar as $ch): ?>
                    <a href="/streamtube.php?slug=<?= urlencode($ch['slug']) ?>" 
                       class="sidebar-item <?= isset($channel) && $channel['id'] == $ch['id'] ? 'active' : '' ?>">
                        <div class="sidebar-item-logo">
                            <?php if ($ch['logo_url']): ?>
                                <img src="<?= e($ch['logo_url']) ?>" alt="">
                            <?php else: ?>
                                <?= e(mb_substr($ch['title'], 0, 2)) ?>
                            <?php endif; ?>
                        </div>
                        <div class="sidebar-item-info">
                            <div class="sidebar-item-name"><?= e($ch['title']) ?></div>
                            <div class="sidebar-item-meta"><?= $ch['type'] === 'radio' ? '📻 Радио' : '📺 ТВ' ?></div>
                        </div>
                        <div class="sidebar-item-status <?= is_channel_live($ch['id']) ? 'live' : 'off' ?>"></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <!-- ОСНОВНОЙ КОНТЕНТ -->
        <main class="streamtube-main">
            <?php if ($channel): ?>
                <!-- ===== СТРАНИЦА КАНАЛА (плеер + информация) ===== -->
                <div class="channel-player-section">
                    <!-- Плеер -->
                    <div class="player-wrapper">
                        <?php if ($activeSource): ?>
                            <div class="player-container">
                                <?php if ($activeSource['type'] === 'mp4'): ?>
                                    <video src="<?= e($activeSource['url']) ?>" controls autoplay class="player-video"></video>
                                <?php elseif ($activeSource['type'] === 'm3u8'): ?>
                                    <video id="hlsPlayer" controls autoplay class="player-video"></video>
                                    <script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
                                    <script>
                                        var video = document.getElementById('hlsPlayer');
                                        if (Hls.isSupported()) {
                                            var hls = new Hls();
                                            hls.loadSource('<?= e($activeSource['url']) ?>');
                                            hls.attachMedia(video);
                                        } else {
                                            video.src = '<?= e($activeSource['url']) ?>';
                                        }
                                    </script>
                                <?php else: ?>
                                    <iframe src="<?= e($activeSource['url']) ?>" 
                                            allowfullscreen 
                                            allow="autoplay; encrypted-media" 
                                            class="player-iframe"></iframe>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="player-offline">
                                <span>📡</span>
                                <p>Канал временно не транслирует</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Информация о канале -->
                    <div class="channel-info">
                        <div class="channel-header">
                            <div class="channel-title-block">
                                <h1 class="channel-title"><?= e($channel['title']) ?></h1>
                                <span class="channel-badge <?= $isLive ? 'live' : 'off' ?>">
                                    <?= $isLive ? '● LIVE' : '● OFF' ?>
                                </span>
                            </div>
                            <div class="channel-actions">
                                <button class="action-btn like-btn" id="likeBtn" onclick="toggleLike()">
                                    👍 <span id="likeCount"><?= (int) $channel['likes_count'] ?? 0 ?></span>
                                </button>
                                <button class="action-btn fav-btn" id="favBtn" onclick="toggleFavorite()">
                                    ⭐ В избранное
                                </button>
                            </div>
                        </div>

                        <div class="channel-meta">
                            <span>👁 <?= (int) $channel['views'] ?> просмотров</span>
                            <span>📅 <?= date('d.m.Y', strtotime($channel['created_at'])) ?></span>
                        </div>

                        <!-- Вкладки -->
                        <div class="channel-tabs">
                            <button class="tab-btn active" data-tab="about">О канале</button>
                            <button class="tab-btn" data-tab="schedule">📋 Расписание</button>
                            <button class="tab-btn" data-tab="comments">💬 Комментарии</button>
                        </div>

                        <div class="tab-content active" id="tab-about">
                            <p class="channel-description"><?= e($channel['description'] ?? 'Описание отсутствует') ?></p>
                        </div>

                        <div class="tab-content" id="tab-schedule">
                            <?php if ($nowPlaying): ?>
                                <div class="now-playing">
                                    <span class="now-label">Сейчас в эфире:</span>
                                    <span class="now-program"><?= e($nowPlaying['program_title'] ?? '—') ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="schedule-grid">
                                <?php if (empty($schedule)): ?>
                                    <p class="empty-message">Расписание не задано</p>
                                <?php else: ?>
                                    <table class="schedule-table">
                                        <thead>
                                            <tr>
                                                <th>День</th>
                                                <th>Время</th>
                                                <th>Программа</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
                                            foreach ($schedule as $item): 
                                            ?>
                                                <tr>
                                                    <td><?= $days[(int)$item['day_of_week']] ?></td>
                                                    <td><?= substr($item['start_time'], 0, 5) ?> – <?= substr($item['end_time'], 0, 5) ?></td>
                                                    <td><?= e($item['program_title'] ?: $item['source_name']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tab-content" id="tab-comments">
                            <div id="commentsContainer">
                                <p class="empty-message">Комментарии загружаются...</p>
                            </div>
                            <?php if ($user): ?>
                                <div class="comment-form">
                                    <input type="text" id="commentInput" placeholder="Написать комментарий..." />
                                    <button onclick="sendComment()">Отправить</button>
                                </div>
                            <?php else: ?>
                                <p class="login-prompt"><a href="/auth/login.php">Войдите</a>, чтобы комментировать</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- ===== ГЛАВНАЯ СТРАНИЦА (каталог + рекомендации) ===== -->
                <div class="catalog-section">
                    <!-- Поиск -->
                    <div class="catalog-header">
                        <h1 class="catalog-title">🎬 Видео</h1>
                        <form method="GET" class="search-form">
                            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Поиск каналов..." />
                            <button type="submit">Найти</button>
                        </form>
                    </div>

                    <!-- Рекомендации -->
                    <?php if (!empty($recommendations)): ?>
                        <section class="recommendations-section">
                            <h2 class="section-title">🔥 Рекомендуем</h2>
                            <div class="video-grid">
                                <?php foreach ($recommendations as $video): ?>
                                    <a href="/video.php?slug=<?= e($video['slug']) ?>" class="video-card">
                                        <div class="video-thumbnail" style="background-image: url('<?= e($video['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>')">
                                            <div class="video-duration"><?= $video['duration'] ?? '--:--' ?></div>
                                        </div>
                                        <div class="video-info">
                                            <h3 class="video-title"><?= e(mb_substr($video['title'], 0, 60)) ?></h3>
                                            <p class="video-channel"><?= e($video['channel_title'] ?? '') ?></p>
                                            <p class="video-meta">👁 <?= (int) $video['views_count'] ?> просмотров</p>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <!-- Все каналы -->
                    <section class="all-channels-section">
                        <h2 class="section-title">📺 Все каналы</h2>
                        <div class="channel-grid">
                            <?php 
                            // Фильтрация каналов
                            $sql = "SELECT * FROM channels WHERE status = 'approved'";
                            $params = [];
                            if ($type) {
                                $sql .= " AND type = ?";
                                $params[] = $type;
                            }
                            if ($search) {
                                $sql .= " AND title LIKE ?";
                                $params[] = "%$search%";
                            }
                            $sql .= " ORDER BY views DESC";
                            $stmt = db()->prepare($sql);
                            $stmt->execute($params);
                            $allChannels = $stmt->fetchAll();

                            if (empty($allChannels)): 
                            ?>
                                <p class="empty-message">Каналов не найдено</p>
                            <?php else: ?>
                                <?php foreach ($allChannels as $ch): ?>
                                    <a href="/streamtube.php?slug=<?= urlencode($ch['slug']) ?>" class="channel-card">
                                        <div class="channel-card-logo">
                                            <?php if ($ch['logo_url']): ?>
                                                <img src="<?= e($ch['logo_url']) ?>" alt="">
                                            <?php else: ?>
                                                <span><?= e(mb_substr($ch['title'], 0, 2)) ?></span>
                                            <?php endif; ?>
                                            <div class="channel-card-badge <?= is_channel_live($ch['id']) ? 'live' : 'off' ?>">
                                                <?= is_channel_live($ch['id']) ? 'LIVE' : 'OFF' ?>
                                            </div>
                                        </div>
                                        <div class="channel-card-info">
                                            <h3 class="channel-card-title"><?= e($ch['title']) ?></h3>
                                            <p class="channel-card-meta">👁 <?= (int) $ch['views'] ?> просмотров</p>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- ===== СТИЛИ (YouTube 2026) ===== -->
<style>
/* ----- Общие переменные ----- */
:root {
    --yt-bg: #0f0f0f;
    --yt-bg-secondary: #1a1a1a;
    --yt-bg-card: #272727;
    --yt-text: #ffffff;
    --yt-text-secondary: #aaaaaa;
    --yt-accent: #ff0000;
    --yt-accent-hover: #cc0000;
    --yt-border: #3a3a3a;
    --yt-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
    --yt-radius: 12px;
}

/* ----- Контейнер ----- */
.streamtube-wrapper {
    background: var(--yt-bg);
    color: var(--yt-text);
    min-height: 100vh;
    font-family: 'Roboto', 'Arial', sans-serif;
}

.streamtube-container {
    display: flex;
    max-width: 1440px;
    margin: 0 auto;
    padding: 16px;
    gap: 24px;
}

/* ----- Боковая панель ----- */
.streamtube-sidebar {
    width: 280px;
    flex-shrink: 0;
    background: var(--yt-bg-secondary);
    border-radius: var(--yt-radius);
    padding: 16px;
    height: fit-content;
    position: sticky;
    top: 80px;
}

.sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.sidebar-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--yt-text);
}

.sidebar-count {
    background: var(--yt-bg-card);
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 12px;
    color: var(--yt-text-secondary);
}

.sidebar-filters {
    display: flex;
    gap: 6px;
    margin-bottom: 16px;
}

.filter-pill {
    background: var(--yt-bg-card);
    border: none;
    color: var(--yt-text-secondary);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: 0.2s;
}

.filter-pill:hover {
    background: #3a3a3a;
    color: var(--yt-text);
}

.filter-pill.active {
    background: var(--yt-accent);
    color: #fff;
}

.sidebar-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-height: 70vh;
    overflow-y: auto;
}

.sidebar-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--yt-text-secondary);
    transition: 0.15s;
    cursor: pointer;
}

.sidebar-item:hover {
    background: var(--yt-bg-card);
    color: var(--yt-text);
}

.sidebar-item.active {
    background: var(--yt-bg-card);
    color: var(--yt-text);
    border-left: 3px solid var(--yt-accent);
}

.sidebar-item-logo {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    background: var(--yt-bg-card);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12px;
    color: var(--yt-text-secondary);
    overflow: hidden;
    flex-shrink: 0;
}

.sidebar-item-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.sidebar-item-info {
    flex: 1;
    min-width: 0;
}

.sidebar-item-name {
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sidebar-item-meta {
    font-size: 11px;
    color: var(--yt-text-secondary);
}

.sidebar-item-status {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.sidebar-item-status.live {
    background: var(--yt-accent);
    box-shadow: 0 0 8px var(--yt-accent);
    animation: pulse-live 1.5s infinite;
}

.sidebar-item-status.off {
    background: var(--yt-text-secondary);
}

/* ----- Основной контент ----- */
.streamtube-main {
    flex: 1;
    min-width: 0;
}

/* ----- Плеер ----- */
.channel-player-section {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.player-wrapper {
    background: #000;
    border-radius: var(--yt-radius);
    overflow: hidden;
    position: relative;
    aspect-ratio: 16 / 9;
}

.player-container {
    width: 100%;
    height: 100%;
}

.player-video {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.player-iframe {
    width: 100%;
    height: 100%;
    border: none;
}

.player-offline {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: var(--yt-bg-secondary);
    color: var(--yt-text-secondary);
    font-size: 18px;
}

.player-offline span {
    font-size: 48px;
    margin-bottom: 12px;
}

/* ----- Информация о канале ----- */
.channel-info {
    background: var(--yt-bg-secondary);
    border-radius: var(--yt-radius);
    padding: 20px;
}

.channel-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 8px;
}

.channel-title-block {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.channel-title {
    font-size: 22px;
    font-weight: 700;
    margin: 0;
}

.channel-badge {
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    letter-spacing: 0.5px;
}

.channel-badge.live {
    background: var(--yt-accent);
    color: #fff;
    animation: pulse-live 2s infinite;
}

.channel-badge.off {
    background: var(--yt-bg-card);
    color: var(--yt-text-secondary);
}

.channel-actions {
    display: flex;
    gap: 8px;
}

.action-btn {
    background: var(--yt-bg-card);
    border: none;
    color: var(--yt-text);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: 0.2s;
}

.action-btn:hover {
    background: #3a3a3a;
}

.like-btn.liked {
    background: var(--yt-accent);
    color: #fff;
}

.fav-btn.favorited {
    background: #ffd700;
    color: #000;
}

.channel-meta {
    display: flex;
    gap: 20px;
    font-size: 13px;
    color: var(--yt-text-secondary);
    margin-bottom: 16px;
}

/* ----- Вкладки ----- */
.channel-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--yt-border);
    margin-bottom: 16px;
}

.tab-btn {
    background: none;
    border: none;
    color: var(--yt-text-secondary);
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: 0.2s;
}

.tab-btn:hover {
    color: var(--yt-text);
}

.tab-btn.active {
    color: var(--yt-text);
    border-bottom-color: var(--yt-accent);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.channel-description {
    color: var(--yt-text-secondary);
    line-height: 1.6;
    font-size: 14px;
}

/* ----- Расписание ----- */
.now-playing {
    background: var(--yt-bg-card);
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.now-label {
    font-weight: 700;
    color: var(--yt-accent);
    font-size: 13px;
}

.now-program {
    color: var(--yt-text);
    font-size: 14px;
}

.schedule-grid {
    overflow-x: auto;
}

.schedule-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.schedule-table th {
    text-align: left;
    padding: 10px 12px;
    color: var(--yt-text-secondary);
    border-bottom: 2px solid var(--yt-border);
}

.schedule-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--yt-border);
}

.schedule-table tr:hover td {
    background: var(--yt-bg-card);
}

/* ----- Комментарии ----- */
.comment-form {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.comment-form input {
    flex: 1;
    background: var(--yt-bg-card);
    border: 1px solid var(--yt-border);
    border-radius: 8px;
    color: var(--yt-text);
    padding: 10px 14px;
    font-size: 14px;
}

.comment-form input:focus {
    outline: none;
    border-color: var(--yt-accent);
}

.comment-form button {
    background: var(--yt-accent);
    border: none;
    color: #fff;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.comment-form button:hover {
    background: var(--yt-accent-hover);
}

.login-prompt {
    color: var(--yt-text-secondary);
    margin-top: 12px;
}

.login-prompt a {
    color: var(--yt-accent);
    text-decoration: none;
}

.login-prompt a:hover {
    text-decoration: underline;
}

.empty-message {
    color: var(--yt-text-secondary);
    text-align: center;
    padding: 20px;
}

/* ----- Каталог и рекомендации ----- */
.catalog-section {
    display: flex;
    flex-direction: column;
    gap: 32px;
}

.catalog-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.catalog-title {
    font-size: 24px;
    font-weight: 700;
    margin: 0;
}

.search-form {
    display: flex;
    gap: 6px;
}

.search-form input {
    background: var(--yt-bg-secondary);
    border: 1px solid var(--yt-border);
    border-radius: 30px;
    color: var(--yt-text);
    padding: 8px 16px;
    font-size: 14px;
    width: 220px;
}

.search-form input:focus {
    outline: none;
    border-color: var(--yt-accent);
}

.search-form button {
    background: var(--yt-accent);
    border: none;
    color: #fff;
    padding: 8px 20px;
    border-radius: 30px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.search-form button:hover {
    background: var(--yt-accent-hover);
}

.section-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 12px;
}

.video-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}

.video-card {
    text-decoration: none;
    color: var(--yt-text);
    transition: 0.2s;
}

.video-card:hover {
    transform: scale(1.02);
}

.video-thumbnail {
    aspect-ratio: 16 / 9;
    background-size: cover;
    background-position: center;
    border-radius: var(--yt-radius);
    position: relative;
}

.video-duration {
    position: absolute;
    bottom: 8px;
    right: 8px;
    background: rgba(0, 0, 0, 0.8);
    color: #fff;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}

.video-info {
    padding: 8px 4px;
}

.video-title {
    font-size: 13px;
    font-weight: 500;
    margin: 0 0 4px 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.video-channel {
    font-size: 12px;
    color: var(--yt-text-secondary);
    margin: 0;
}

.video-meta {
    font-size: 11px;
    color: var(--yt-text-secondary);
    margin: 0;
}

/* ----- Каналы в каталоге ----- */
.channel-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
}

.channel-card {
    background: var(--yt-bg-secondary);
    border-radius: var(--yt-radius);
    overflow: hidden;
    text-decoration: none;
    color: var(--yt-text);
    transition: 0.2s;
    border: 1px solid transparent;
}

.channel-card:hover {
    border-color: var(--yt-accent);
    transform: translateY(-4px);
    box-shadow: var(--yt-shadow);
}

.channel-card-logo {
    aspect-ratio: 16 / 9;
    background: var(--yt-bg-card);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    padding: 16px;
}

.channel-card-logo img {
    max-width: 70%;
    max-height: 70%;
    object-fit: contain;
}

.channel-card-logo span {
    font-size: 24px;
    font-weight: 700;
    color: var(--yt-text-secondary);
}

.channel-card-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    font-size: 9px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 4px;
    letter-spacing: 0.5px;
}

.channel-card-badge.live {
    background: var(--yt-accent);
    color: #fff;
    animation: pulse-live 2s infinite;
}

.channel-card-badge.off {
    background: var(--yt-bg-card);
    color: var(--yt-text-secondary);
}

.channel-card-info {
    padding: 10px 12px;
}

.channel-card-title {
    font-size: 13px;
    font-weight: 500;
    margin: 0 0 4px 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.channel-card-meta {
    font-size: 11px;
    color: var(--yt-text-secondary);
    margin: 0;
}

/* ----- Анимации ----- */
@keyframes pulse-live {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

/* ----- Адаптивность ----- */
@media (max-width: 1024px) {
    .streamtube-sidebar {
        display: none;
    }
}

@media (max-width: 768px) {
    .streamtube-container {
        padding: 8px;
        gap: 12px;
    }

    .channel-title {
        font-size: 18px;
    }

    .video-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }

    .channel-grid {
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    }

    .catalog-header {
        flex-direction: column;
        align-items: stretch;
    }

    .search-form input {
        width: 100%;
    }
}
</style>

<!-- ===== JAVASCRIPT ===== -->
<script>
// ---- Переменные ----
const channelId = <?= isset($channel) ? (int)$channel['id'] : 'null' ?>;
const csrfToken = <?= json_encode($csrf_token) ?>;
const currentUserId = <?= $user ? (int)$user['id'] : 'null' ?>;

// ---- Вкладки ----
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});

// ---- Лайки ----
async function toggleLike() {
    <?php if (!$user): ?>
    location.href = '/auth/login.php';
    return;
    <?php endif; ?>
    if (!channelId) return;
    const btn = document.getElementById('likeBtn');
    const countSpan = document.getElementById('likeCount');
    try {
        const resp = await fetch('/channel_like', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel_id: channelId })
        });
        const data = await resp.json();
        if (data.ok) {
            btn.classList.toggle('liked', data.liked);
            countSpan.textContent = data.count;
        }
    } catch (e) { console.error(e); }
}

// ---- Избранное ----
async function toggleFavorite() {
    <?php if (!$user): ?>
    location.href = '/auth/login.php';
    return;
    <?php endif; ?>
    if (!channelId) return;
    const btn = document.getElementById('favBtn');
    try {
        const resp = await fetch('/channel_favorite', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel_id: channelId })
        });
        const data = await resp.json();
        if (data.ok) {
            btn.classList.toggle('favorited', data.favorited);
            btn.textContent = data.favorited ? '⭐ В избранном' : '⭐ В избранное';
        }
    } catch (e) { console.error(e); }
}

// ---- Комментарии ----
async function loadComments() {
    if (!channelId) return;
    const container = document.getElementById('commentsContainer');
    if (!container) return;
    try {
        const resp = await fetch(`/smotrim.php?action=info&channel_id=${channelId}`);
        const data = await resp.json();
        if (!data.ok) throw new Error();
        if (data.comments && data.comments.length) {
            container.innerHTML = data.comments.map(c =>
                `<div style="padding:8px 0;border-bottom:1px solid var(--yt-border);">
                    <strong style="color:var(--yt-accent);">${escapeHtml(c.username)}</strong>
                    <span style="color:var(--yt-text-secondary);font-size:12px;margin-left:10px;">${escapeHtml(c.created_at)}</span>
                    <br>
                    <span style="color:var(--yt-text-secondary);">${escapeHtml(c.message)}</span>
                </div>`
            ).join('');
        } else {
            container.innerHTML = '<p class="empty-message">Комментариев пока нет.</p>';
        }
    } catch (e) {
        container.innerHTML = '<p class="empty-message">Ошибка загрузки комментариев</p>';
    }
}

async function sendComment() {
    const input = document.getElementById('commentInput');
    if (!input) return;
    const message = input.value.trim();
    if (!message) return;
    try {
        const resp = await fetch('/comment_add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `channel_id=${channelId}&message=${encodeURIComponent(message)}&csrf_token=${encodeURIComponent(csrfToken)}`
        });
        if (resp.ok) {
            input.value = '';
            loadComments();
        } else {
            alert('Ошибка отправки комментария');
        }
    } catch (e) { alert('Сетевая ошибка'); }
}

function escapeHtml(s) {
    return (s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ---- Автозагрузка комментариев ----
document.addEventListener('DOMContentLoaded', function() {
    if (channelId) {
        loadComments();
    }
});
</script>

<?php
// ---- Подключение подвала ----
require_once __DIR__ . '/includes/footer.php';
?>


