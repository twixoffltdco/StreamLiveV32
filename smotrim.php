<?php
/**
 * smotrim.php — главная страница StreamTV с каталогом и страницей канала
 * Исправлена работа на мобильных устройствах, поиск работает по вводу и Enter
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// ===== ВСТРОЕННОЕ API =====
if (isset($_GET['action']) && $_GET['action'] === 'info' && isset($_GET['channel_id'])) {
    header('Content-Type: application/json');
    $channel_id = (int)$_GET['channel_id'];
    if (!$channel_id) { echo json_encode(['ok' => false, 'error' => 'No channel id']); exit; }
    try {
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
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ===== ОСНОВНАЯ ЛОГИКА =====
$slug = $_GET['slug'] ?? '';
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? null;
$type = in_array($type, ['tv', 'radio']) ? $type : null;

$__user = current_user();
$csrf_token = csrf_token();

// Получаем список каналов для сайдбара (только существующие колонки)
try {
    $stmtSidebar = db()->prepare('SELECT id, slug, title, logo_url, type FROM channels WHERE status = "approved" ORDER BY views DESC LIMIT 50');
    $stmtSidebar->execute();
    $channelsSidebar = $stmtSidebar->fetchAll();
} catch (Exception $e) {
    die('Ошибка получения списка каналов: ' . $e->getMessage());
}

$content = '';
$pageTitle = 'StreamTV — Прямые эфиры';
$seoDescription = 'Смотрите прямые эфиры популярных телеканалов и радиостанций.';
$seoKeywords = 'прямой эфир, тв онлайн, телеканалы, радио, стриминг';
$channel = null;

if ($slug) {
    try {
        $stmt = db()->prepare('SELECT * FROM channels WHERE slug = ?');
        $stmt->execute([$slug]);
        $channel = $stmt->fetch();
    } catch (Exception $e) {
        die('Ошибка получения канала: ' . $e->getMessage());
    }

    if (!$channel) {
        http_response_code(404);
        $pageTitle = 'Канал не найден';
        $content = '<div class="container"><div class="empty-state"><h2>Канал не найден</h2><p>Такого канала не существует</p><a href="/smotrim.php" class="btn btn-primary" style="margin-top:14px">В каталог</a></div></div>';
    } elseif ($channel['status'] !== 'approved') {
        http_response_code(403);
        $pageTitle = 'Канал недоступен';
        $msg = $channel['status'] === 'pending' ? 'Канал ещё проходит модерацию и пока не допущен в каталог' : 'Канал не был допущен в каталог' . ($channel['reject_reason'] ? ': ' . e($channel['reject_reason']) : '');
        $content = '<div class="container"><div class="empty-state"><h2>Канал недоступен</h2><p>' . $msg . '</p><a href="/smotrim.php" class="btn btn-primary" style="margin-top:14px">В каталог</a></div></div>';
    } elseif ($__user && is_user_banned_on_channel($channel['id'], $__user['id'])) {
        http_response_code(403);
        $pageTitle = 'Доступ закрыт';
        $content = '<div class="container"><div class="empty-state"><h2>УВЫ, ВАС ЗАБЛОКИРОВАЛИ</h2><p>Модератор этого канала закрыл вам доступ к просмотру и чату.</p><a href="/smotrim.php" class="btn btn-primary" style="margin-top:14px">В каталог</a></div></div>';
    } else {
        // Увеличиваем просмотры
        try {
            db()->prepare('UPDATE channels SET views = views + 1 WHERE id = ?')->execute([$channel['id']]);
        } catch (Exception $e) {}

        $activeSource = resolve_active_source($channel);
        $isLive = $activeSource !== null;
        $likeCount = 0;
        try {
            $likeCount = (int)db()->query('SELECT COUNT(*) FROM channel_likes WHERE channel_id = ' . (int)$channel['id'])->fetchColumn();
        } catch (Exception $e) {}
        $liked = false;
        if ($__user) {
            try {
                $stmtLike = db()->prepare('SELECT id FROM channel_likes WHERE channel_id = ? AND user_id = ?');
                $stmtLike->execute([$channel['id'], $__user['id']]);
                $liked = (bool)$stmtLike->fetch();
            } catch (Exception $e) {}
        }
        $favorited = false;
        if ($__user) {
            try {
                $stmtFav = db()->prepare('SELECT id FROM favorites WHERE channel_id = ? AND user_id = ?');
                $stmtFav->execute([$channel['id'], $__user['id']]);
                $favorited = (bool)$stmtFav->fetch();
            } catch (Exception $e) {}
        }

        $pageTitle = $channel['seo_title'] ?: $channel['title'];
        $seoDescription = $channel['seo_description'] ?: $channel['description'];
        $seoKeywords = $channel['seo_keywords'] ?: '';

        ob_start();
        ?>
        <div class="player-view">
            <div class="embed-wrap">
                <iframe id="embedFrame"
                    src="https://streamlive.freedev.app/embed.php?slug=<?= urlencode($slug) ?>"
                    allowfullscreen
                    allow="autoplay; fullscreen; encrypted-media; picture-in-picture"
                    referrerpolicy="no-referrer-when-downgrade">
                </iframe>
            </div>

            <div class="pib">
                <div class="pib-logo">
                    <?php if ($channel['logo_url']): ?>
                        <img src="<?= e($channel['logo_url']) ?>" alt="">
                    <?php else: ?>
                        <?= e(mb_substr($channel['title'], 0, 3)) ?>
                    <?php endif; ?>
                </div>
                <div class="pib-name"><?= e($channel['title']) ?></div>
                <div class="pib-owner">@<?= e($channel['owner_username'] ?? '') ?></div>
                <div class="<?= $isLive ? 'pib-live' : 'pib-off' ?>">
                    <?= $isLive ? 'LIVE' : 'OFF' ?>
                </div>
            </div>

            <div class="channel-info" id="channelInfo">
                <div class="action-row">
                    <button class="action-btn-page <?= $liked ? 'liked' : '' ?>" id="likeBtn" onclick="toggleLike()">
                        ♥ <span id="likeCount"><?= $likeCount ?></span>
                    </button>
                    <button class="action-btn-page <?= $favorited ? 'favorited' : '' ?>" id="favBtn" onclick="toggleFavorite()">
                        <?= $favorited ? '★' : '☆' ?> Избранное
                    </button>
                </div>

                <div class="tabs">
                    <button class="tab active" data-tab="about" onclick="switchTab('about')">О канале</button>
                    <button class="tab" data-tab="schedule" onclick="switchTab('schedule')">Расписание</button>
                    <button class="tab" data-tab="comments" onclick="switchTab('comments')">Комментарии</button>
                </div>

                <div class="tab-content active" id="tab-about">
                    <p class="desc"><?= e($channel['description'] ?? 'Нет описания') ?></p>
                </div>
                <div class="tab-content" id="tab-schedule">
                    <table class="schedule-table">
                        <thead><tr><th>День</th><th>Время</th><th>Программа</th></tr></thead>
                        <tbody id="scheduleBody">
                            <tr><td colspan="3" style="color:var(--text3);text-align:center;">Загрузка...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="tab-content" id="tab-comments">
                    <div id="commentsContainer">
                        <div style="color:var(--text3);text-align:center;">Загрузка...</div>
                    </div>
                    <?php if ($__user): ?>
                    <div class="comment-input">
                        <input type="text" id="commentInput" placeholder="Написать комментарий..." />
                        <button onclick="sendComment()">Отправить</button>
                    </div>
                    <?php else: ?>
                    <p style="color:var(--text3);margin-top:10px;"><a href="/auth/login.php" style="color:var(--accent);">Войдите</a>, чтобы комментировать</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        $content = ob_get_clean();
    }
} else {
    // === КАТАЛОГ ===
    try {
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
    } catch (Exception $e) {
        die('Ошибка получения каналов: ' . $e->getMessage());
    }

    ob_start();
    ?>
    <div class="grid-wrap">
        <?php if (empty($allChannels)): ?>
            <div class="state-screen">
                <span><?= $search ? 'По вашему запросу ничего не найдено' : 'Каналов пока нет' ?></span>
                <?php if ($search): ?>
                    <a href="/smotrim.php" class="btn btn-primary" style="margin-top:14px">Показать все</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($search): ?>
                <div class="sec-lbl">🔍 Результаты поиска: <?= e($search) ?> (<?= count($allChannels) ?>)</div>
            <?php else: ?>
                <div class="sec-lbl">📺 Все каналы</div>
            <?php endif; ?>
            <div class="ch-grid">
                <?php foreach ($allChannels as $ch): 
                    $activeSource = resolve_active_source($ch);
                    $isLive = $activeSource !== null;
                ?>
                <div class="card" onclick="window.open('/smotrim.php?slug=<?= urlencode($ch['slug']) ?>', '_blank')">
                    <div class="card-thumb">
                        <?php if ($ch['logo_url']): ?>
                            <img src="<?= e($ch['logo_url']) ?>" onerror="this.remove()">
                        <?php else: ?>
                            <div class="card-fb"><?= e(mb_substr($ch['title'], 0, 7)) ?></div>
                        <?php endif; ?>
                        <div class="play-ov"><div class="play-ic">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        </div></div>
                        <div class="badge <?= $isLive ? '' : 'off' ?>"><?= $isLive ? 'LIVE' : 'OFF' ?></div>
                        <?php if ($ch['type'] === 'radio'): ?><div class="rbadge">RADIO</div><?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="card-name"><?= e($ch['title']) ?></div>
                        <div class="card-sub"><?= e($ch['owner_username'] ?? '') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    $content = ob_get_clean();
}
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="StreamTV" />
    <meta name="theme-color" content="#080c14" />

    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($seoDescription) ?>" />
    <meta name="keywords" content="<?= e($seoKeywords) ?>" />
    <link rel="canonical" href="https://streamlive.freedev.app/smotrim.php<?= $slug ? '?slug=' . urlencode($slug) : '' ?>" />

    <!-- Open Graph -->
    <meta property="og:title" content="<?= e($pageTitle) ?>" />
    <meta property="og:description" content="<?= e($seoDescription) ?>" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://streamlive.freedev.app/smotrim.php<?= $slug ? '?slug=' . urlencode($slug) : '' ?>" />
    <?php if (isset($channel) && $channel['logo_url']): ?>
    <meta property="og:image" content="<?= e($channel['logo_url']) ?>" />
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <style>
        /* ===== СТИЛЬ STREAMTV ===== */
        :root { --bg: #080c14; --bg2: #0d1220; --bg3: #141926; --bg4: #1a2133; --accent: #e8303a; --text: #f0f4ff; --text2: #8a9bb8; --text3: #4a5a7a; --border: #1e2a40; --r: 12px; --safe-bottom: env(safe-area-inset-bottom, 0px); }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html,body{height:100%;overflow:hidden}
        body{font-family:'Manrope',sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column;height:100dvh}
        .header{background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;padding:0 16px;height:56px;flex-shrink:0;z-index:50;padding-top:env(safe-area-inset-top,0)}
        .logo{font-family:'Bebas Neue',sans-serif;font-size:24px;letter-spacing:2px;display:flex;align-items:center;gap:7px;white-space:nowrap}
        .logo span{color:var(--accent)}
        .logo-dot{width:7px;height:7px;background:var(--accent);border-radius:50%;box-shadow:0 0 7px var(--accent);animation:blink 1.5s ease-in-out infinite;flex-shrink:0}
        @keyframes blink{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
        .h-search{flex:1;position:relative;max-width:340px}
        .h-search input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'Manrope',sans-serif;font-size:14px;padding:8px 12px 8px 34px;outline:none;transition:border-color .2s}
        .h-search input:focus{border-color:var(--accent)}
        .h-search input::placeholder{color:var(--text3)}
        .h-search .ico{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);pointer-events:none}
        .h-right{display:flex;align-items:center;gap:8px;margin-left:auto}
        .icon-btn{width:38px;height:38px;border-radius:9px;background:var(--bg3);border:1px solid var(--border);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0}
        .icon-btn:hover,.icon-btn.on{background:var(--accent);border-color:var(--accent);color:#fff}
        .main{display:flex;flex:1;overflow:hidden;position:relative}
        .sidebar{width:280px;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0;overflow:hidden;transition:transform .3s ease}
        .sb-head{padding:12px 14px 8px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .sb-label{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3)}
        .sb-count{font-size:11px;color:var(--text3);background:var(--bg3);padding:2px 8px;border-radius:20px}
        .type-pills{display:flex;gap:5px;padding:8px 10px;border-bottom:1px solid var(--border);flex-shrink:0;overflow-x:auto;scrollbar-width:none}
        .type-pills::-webkit-scrollbar{display:none}
        .pill{padding:4px 11px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:none;color:var(--text2);white-space:nowrap;transition:all .2s}
        .pill:hover{background:var(--bg4);color:var(--text)}
        .pill.on{border-color:var(--accent);color:var(--accent);background:rgba(232,48,58,.08)}
        .ch-list{flex:1;overflow-y:auto;padding:6px;scrollbar-width:thin;scrollbar-color:var(--bg4) transparent}
        .ch-list::-webkit-scrollbar{width:3px}
        .ch-list::-webkit-scrollbar-thumb{background:var(--bg4);border-radius:4px}
        .ch-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;cursor:pointer;transition:all .15s;border:1px solid transparent;margin-bottom:2px;-webkit-tap-highlight-color:transparent}
        .ch-item:hover{background:var(--bg3)}
        .ch-item.on{background:var(--bg4);border-color:var(--accent)}
        .ch-logo{width:42px;height:28px;border-radius:6px;background:var(--bg4);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;font-size:8px;font-weight:700;color:var(--text3);text-align:center}
        .ch-logo img{width:100%;height:100%;object-fit:contain;padding:3px}
        .ch-info{flex:1;min-width:0}
        .ch-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ch-meta{font-size:11px;color:var(--text3);margin-top:1px}
        .live-pip{width:6px;height:6px;background:#3dd68c;border-radius:50%;flex-shrink:0;box-shadow:0 0 5px #3dd68c;animation:blink 2s infinite}
        .off-pip{width:6px;height:6px;background:var(--text3);border-radius:50%;flex-shrink:0}
        .content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
        .grid-wrap{flex:1;overflow-y:auto;padding:16px;scrollbar-width:thin;scrollbar-color:var(--bg4) transparent}
        .grid-wrap::-webkit-scrollbar{width:5px}
        .grid-wrap::-webkit-scrollbar-thumb{background:var(--bg4);border-radius:4px}
        .sec-lbl{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3);margin-bottom:12px;display:flex;align-items:center;gap:8px}
        .sec-lbl::after{content:'';flex:1;height:1px;background:var(--border)}
        .ch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:24px}
        .card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;cursor:pointer;transition:all .2s;position:relative;-webkit-tap-highlight-color:transparent}
        .card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.4)}
        .card:active{transform:scale(.97)}
        .card-thumb{height:82px;background:var(--bg4);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
        .card-thumb img{max-width:70%;max-height:62%;object-fit:contain;filter:drop-shadow(0 2px 5px rgba(0,0,0,.5))}
        .card-fb{color:var(--text3);font-size:10px;font-weight:700;text-align:center;padding:4px;line-height:1.3}
        .play-ov{position:absolute;inset:0;background:rgba(232,48,58,0);display:flex;align-items:center;justify-content:center;transition:background .2s;pointer-events:none}
        .card:hover .play-ov{background:rgba(232,48,58,.18)}
        .play-ic{width:34px;height:34px;border-radius:50%;background:rgba(232,48,58,.9);display:flex;align-items:center;justify-content:center;opacity:0;transform:scale(.6);transition:all .2s;pointer-events:auto}
        .card:hover .play-ic,.card:active .play-ic{opacity:1;transform:scale(1)}
        .badge{position:absolute;top:6px;right:6px;background:var(--accent);color:#fff;font-size:8px;font-weight:700;padding:2px 5px;border-radius:3px;letter-spacing:.5px}
        .badge.off{background:rgba(255,255,255,.08);color:var(--text3)}
        .rbadge{position:absolute;top:6px;left:6px;background:rgba(61,214,140,.12);color:#3dd68c;font-size:8px;font-weight:700;padding:2px 5px;border-radius:3px;border:1px solid rgba(61,214,140,.3)}
        .card-body{padding:8px 10px}
        .card-name{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .card-sub{font-size:10px;color:var(--text3);margin-top:2px}
        .player-view{display:flex;flex-direction:column;height:100%;overflow:hidden}
        .embed-wrap{width:100%;position:relative;background:#000;flex-shrink:0}
        .embed-wrap::after{content:'';display:block;padding-bottom:56.25%}
        .embed-wrap iframe{position:absolute;inset:0;width:100%;height:100%;border:none}
        .pib{padding:10px 16px;background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;min-width:0}
        .pib-logo{width:42px;height:28px;border-radius:6px;background:var(--bg4);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;font-size:8px;font-weight:700;color:var(--text3)}
        .pib-logo img{width:100%;height:100%;object-fit:contain;padding:3px}
        .pib-name{font-size:15px;font-weight:700;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .pib-owner{font-size:11px;color:var(--text3);flex-shrink:0}
        .pib-live{background:var(--accent);color:#fff;font-size:10px;font-weight:700;letter-spacing:1px;padding:3px 8px;border-radius:4px;display:flex;align-items:center;gap:4px;flex-shrink:0}
        .pib-live::before{content:'';width:5px;height:5px;background:#fff;border-radius:50%;animation:blink 1s infinite}
        .pib-off{background:var(--bg4);color:var(--text3);font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px;flex-shrink:0}
        .channel-info{padding:20px;overflow-y:auto;flex:1}
        .channel-info .desc{color:var(--text2);margin-bottom:20px}
        .channel-info .tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:16px}
        .channel-info .tab{padding:8px 16px;background:transparent;border:none;color:var(--text3);cursor:pointer;font-weight:600;border-bottom:2px solid transparent;transition:0.2s}
        .channel-info .tab.active{color:var(--accent);border-bottom-color:var(--accent)}
        .channel-info .tab-content{display:none}
        .channel-info .tab-content.active{display:block}
        .channel-info .schedule-table{width:100%;border-collapse:collapse}
        .channel-info .schedule-table th{text-align:left;padding:6px 8px;color:var(--text3);border-bottom:1px solid var(--border)}
        .channel-info .schedule-table td{padding:6px 8px;border-bottom:1px solid var(--bg4)}
        .channel-info .comment-item{padding:8px 0;border-bottom:1px solid var(--bg4)}
        .channel-info .comment-item .user{color:var(--accent);font-weight:600}
        .channel-info .comment-item .time{color:var(--text3);font-size:12px;margin-left:10px}
        .channel-info .comment-input{display:flex;gap:8px;margin-top:12px}
        .channel-info .comment-input input{flex:1;background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:8px 12px}
        .channel-info .comment-input button{background:var(--accent);border:none;color:#fff;padding:8px 16px;border-radius:8px;cursor:pointer}
        .action-row{display:flex;gap:12px;align-items:center;margin:12px 0}
        .action-btn-page{background:var(--bg3);border:1px solid var(--border);border-radius:30px;padding:6px 14px;color:var(--text2);cursor:pointer;display:flex;align-items:center;gap:6px;transition:0.2s}
        .action-btn-page:hover{background:var(--bg4)}
        .action-btn-page.liked{color:#ff2d55;border-color:#ff2d55}
        .action-btn-page.favorited{color:#ffcc00;border-color:#ffcc00}
        .bottom-nav{display:none;background:var(--bg2);border-top:1px solid var(--border);flex-shrink:0;z-index:60;padding-bottom:var(--safe-bottom)}
        .bn-inner{display:flex;height:56px}
        .bn-tab{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;color:var(--text3);font-size:10px;font-weight:600;transition:all .2s;-webkit-tap-highlight-color:transparent;border:none;background:none}
        .bn-tab svg{transition:all .2s}
        .bn-tab.on{color:var(--accent)}
        .bn-tab.on svg{filter:drop-shadow(0 0 4px var(--accent))}
        .drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:70;opacity:0;transition:opacity .3s}
        .drawer-overlay.open{display:block;opacity:1}
        .drawer{position:fixed;left:0;top:0;bottom:0;width:min(320px,85vw);background:var(--bg2);z-index:80;display:flex;flex-direction:column;transform:translateX(-100%);transition:transform .3s ease;padding-top:env(safe-area-inset-top,0);padding-bottom:var(--safe-bottom)}
        .drawer.open{transform:translateX(0)}
        .drawer-head{padding:14px 16px 10px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .drawer-title{font-family:'Bebas Neue',sans-serif;font-size:20px;letter-spacing:1px;display:flex;align-items:center;gap:7px}
        .drawer-title span{color:var(--accent)}
        .drawer-close{width:32px;height:32px;border-radius:8px;background:var(--bg3);border:1px solid var(--border);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}
        .drawer-search{padding:10px 12px;border-bottom:1px solid var(--border);flex-shrink:0}
        .drawer-search input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'Manrope',sans-serif;font-size:14px;padding:9px 12px;outline:none;transition:border-color .2s}
        .drawer-search input:focus{border-color:var(--accent)}
        .drawer-search input::placeholder{color:var(--text3)}
        .drawer-pills{display:flex;gap:5px;padding:8px 10px;border-bottom:1px solid var(--border);flex-shrink:0;overflow-x:auto;scrollbar-width:none}
        .drawer-pills::-webkit-scrollbar{display:none}
        .drawer-list{flex:1;overflow-y:auto;padding:6px;-webkit-overflow-scrolling:touch}
        .state-screen{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;color:var(--text3)}
        .empty-state{text-align:center;padding:40px 20px}
        .empty-state h2{color:var(--text2);margin-bottom:8px}
        .btn{display:inline-block;padding:10px 20px;border-radius:8px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;border:none;cursor:pointer}
        .btn-primary{background:var(--accent)}
        .container{max-width:1200px;margin:0 auto;padding:20px}
        @media(max-width:768px){.sidebar{display:none}.bottom-nav{display:block}.h-search{display:none}.ch-grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}.card-thumb{height:72px}.grid-wrap{padding:12px}
        .action-row{flex-wrap:wrap;margin-bottom:60px}
        .channel-info .tab{padding:10px 12px;font-size:13px;flex:1;text-align:center}
        .card .card-thumb .play-ov{display:none}
        }
        @media(min-width:769px){.drawer,.drawer-overlay{display:none!important}}
    </style>
</head>
<body>
    <!-- HEADER -->
    <div class="header">
        <div class="logo"><div class="logo-dot"></div>Stream<span>TV</span></div>
        <div class="h-search">
            <svg class="ico" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" /></svg>
            <input type="text" id="desktopSearch" placeholder="Поиск каналов..." value="<?= e($search) ?>">
        </div>
        <div class="h-right">
            <a href="/smotrim.php" class="icon-btn" title="Каталог">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /><rect x="14" y="14" width="7" height="7" /></svg>
            </a>
            <?php if ($__user): ?>
                <a href="/dashboard.php" class="icon-btn" title="Профиль">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                </a>
                <a href="/auth/logout.php" class="icon-btn" title="Выйти">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /><polyline points="16 17 21 12 16 7" /><line x1="21" y1="12" x2="9" y2="12" /></svg>
                </a>
            <?php else: ?>
                <a href="/auth/login.php" class="icon-btn" title="Войти">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" /><polyline points="10 17 15 12 10 7" /><line x1="15" y1="12" x2="3" y2="12" /></svg>
                </a>
            <?php endif; ?>
            <button class="icon-btn" id="btnBurger" onclick="openDrawer()" style="display:none">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" /></svg>
            </button>
        </div>
    </div>

    <!-- MAIN -->
    <div class="main">
        <!-- САЙДБАР -->
        <div class="sidebar" id="sidebar">
            <div class="sb-head">
                <span class="sb-label">Каналы</span>
                <span class="sb-count" id="sbCount"><?= count($channelsSidebar) ?></span>
            </div>
            <div class="type-pills">
                <button class="pill <?= !$type && !$search ? 'on' : '' ?>" onclick="location.href='/smotrim.php'">Все</button>
                <button class="pill <?= $type === 'tv' ? 'on' : '' ?>" onclick="location.href='/smotrim.php?type=tv'">📺 ТВ</button>
                <button class="pill <?= $type === 'radio' ? 'on' : '' ?>" onclick="location.href='/smotrim.php?type=radio'">📻 Радио</button>
            </div>
            <div class="ch-list" id="sidebarList">
                <?php foreach ($channelsSidebar as $ch): ?>
                <div class="ch-item <?= isset($channel) && $channel['id'] == $ch['id'] ? 'on' : '' ?>" onclick="window.open('/smotrim.php?slug=<?= urlencode($ch['slug']) ?>', '_blank')">
                    <div class="ch-logo">
                        <?php if ($ch['logo_url']): ?>
                            <img src="<?= e($ch['logo_url']) ?>" onerror="this.style.display='none'">
                        <?php else: ?>
                            <?= e(mb_substr($ch['title'], 0, 4)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="ch-info">
                        <div class="ch-name"><?= e($ch['title']) ?></div>
                        <div class="ch-meta"><?= $ch['type'] === 'radio' ? '📻' : '📺' ?></div>
                    </div>
                    <div class="off-pip"></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- КОНТЕНТ -->
        <div class="content" id="content">
            <?= $content ?>
        </div>
    </div>

    <!-- MOBILE BOTTOM NAV -->
    <nav class="bottom-nav">
        <div class="bn-inner">
            <button class="bn-tab on" onclick="location.href='/smotrim.php'">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /><rect x="14" y="14" width="7" height="7" /></svg>
                Каналы
            </button>
            <button class="bn-tab" onclick="location.href='/smotrim.php'">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3" /></svg>
                Плеер
            </button>
            <button class="bn-tab" onclick="document.getElementById('desktopSearch').focus()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.35-4.35" /></svg>
                Поиск
            </button>
            <button class="bn-tab" onclick="openDrawer()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" /></svg>
                Список
            </button>
        </div>
    </nav>

    <!-- MOBILE DRAWER -->
    <div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
    <div class="drawer" id="drawer">
        <div class="drawer-head">
            <div class="drawer-title"><div class="logo-dot"></div>Stream<span>TV</span></div>
            <button class="drawer-close" onclick="closeDrawer()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
            </button>
        </div>
        <div class="drawer-search">
            <input type="text" id="drawerSearch" placeholder="Поиск каналов..." value="<?= e($search) ?>">
        </div>
        <div class="drawer-pills">
            <button class="pill <?= !$type && !$search ? 'on' : '' ?>" onclick="location.href='/smotrim.php'">Все</button>
            <button class="pill <?= $type === 'tv' ? 'on' : '' ?>" onclick="location.href='/smotrim.php?type=tv'">📺 ТВ</button>
            <button class="pill <?= $type === 'radio' ? 'on' : '' ?>" onclick="location.href='/smotrim.php?type=radio'">📻 Радио</button>
        </div>
        <div class="drawer-list ch-list" id="drawerList">
            <?php foreach ($channelsSidebar as $ch): ?>
            <div class="ch-item <?= isset($channel) && $channel['id'] == $ch['id'] ? 'on' : '' ?>" onclick="window.open('/smotrim.php?slug=<?= urlencode($ch['slug']) ?>', '_blank')">
                <div class="ch-logo">
                    <?php if ($ch['logo_url']): ?>
                        <img src="<?= e($ch['logo_url']) ?>" onerror="this.style.display='none'">
                    <?php else: ?>
                        <?= e(mb_substr($ch['title'], 0, 4)) ?>
                    <?php endif; ?>
                </div>
                <div class="ch-info">
                    <div class="ch-name"><?= e($ch['title']) ?></div>
                    <div class="ch-meta"><?= $ch['type'] === 'radio' ? '📻' : '📺' ?></div>
                </div>
                <div class="off-pip"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        const channelId = <?= isset($channel) ? (int)$channel['id'] : 'null' ?>;
        const csrfToken = <?= json_encode($csrf_token) ?>;
        const currentUserId = <?= $__user ? (int)$__user['id'] : 'null' ?>;

        // ===== Лайки =====
        async function toggleLike() {
            <?php if (!$__user): ?>
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

        async function toggleFavorite() {
            <?php if (!$__user): ?>
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
                    btn.innerHTML = data.favorited ? '★ Избранное' : '☆ Избранное';
                }
            } catch (e) { console.error(e); }
        }

        // ===== Вкладки =====
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelector(`.tab[data-tab="${tab}"]`)?.classList.add('active');
            document.getElementById(`tab-${tab}`)?.classList.add('active');
            if (tab === 'schedule') loadSchedule();
            if (tab === 'comments') loadComments();
        }

        // ===== Расписание =====
        async function loadSchedule() {
            if (!channelId) return;
            const tbody = document.getElementById('scheduleBody');
            if (!tbody) return;
            try {
                const resp = await fetch(`/smotrim.php?action=info&channel_id=${channelId}`);
                const data = await resp.json();
                if (!data.ok) throw new Error();
                if (data.schedule && data.schedule.length) {
                    tbody.innerHTML = data.schedule.map(s =>
                        `<tr><td>${s.day}</td><td>${s.start_time}–${s.end_time}</td><td>${escapeHtml(s.program_title || s.source_name)}</td></tr>`
                    ).join('');
                } else {
                    tbody.innerHTML = '<tr><td colspan="3" style="color:var(--text3);text-align:center;">Расписание не задано</td></tr>';
                }
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="3" style="color:var(--text3);text-align:center;">Ошибка загрузки</td></tr>';
            }
        }

        // ===== Комментарии =====
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
                        `<div class="comment-item">
                            <span class="user">${escapeHtml(c.username)}</span>
                            <span class="time">${escapeHtml(c.created_at)}</span>
                            <br>${escapeHtml(c.message)}
                        </div>`
                    ).join('');
                } else {
                    container.innerHTML = '<p style="color:var(--text3);">Комментариев пока нет.</p>';
                }
            } catch (e) {
                container.innerHTML = '<p style="color:var(--text3);">Ошибка загрузки комментариев</p>';
            }
        }

        // ===== Отправка комментария =====
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

        // ===== Мобильный дровер =====
        function openDrawer() {
            document.getElementById('drawer').classList.add('open');
            document.getElementById('drawerOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeDrawer() {
            document.getElementById('drawer').classList.remove('open');
            document.getElementById('drawerOverlay').classList.remove('open');
            document.body.style.overflow = '';
        }
        document.getElementById('drawerOverlay')?.addEventListener('click', closeDrawer);

        // ===== ПОИСК (работает и на мобилках) =====
        function doSearch(val) {
            val = val.trim();
            if (val.length === 0) {
                location.href = '/smotrim.php';
                return;
            }
            // Поддерживаем фильтры типа (если задан)
            let url = '/smotrim.php?search=' + encodeURIComponent(val);
            <?php if ($type): ?>
            url += '&type=<?= e($type) ?>';
            <?php endif; ?>
            location.href = url;
        }

        // Поиск по Enter
        document.getElementById('desktopSearch')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                doSearch(this.value);
            }
        });
        document.getElementById('drawerSearch')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                doSearch(this.value);
            }
        });

        // Поиск по вводу с debounce (на мобилке удобно)
        let searchTimeout = null;
        document.getElementById('desktopSearch')?.addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                doSearch(this.value);
            }, 500);
        });
        document.getElementById('drawerSearch')?.addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                doSearch(this.value);
            }, 500);
        });

        // ===== АВТОЗАГРУЗКА РАСПИСАНИЯ И КОММЕНТАРИЕВ =====
        document.addEventListener('DOMContentLoaded', function() {
            if (channelId) {
                loadSchedule();
                loadComments();
            }
        });
    </script>
</body>
</html>