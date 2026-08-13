<?php
/**
 * streamtok.php — TikTok-лента с 5 вкладками:
 * Для вас (все типы с рекомендациями), Подписки, Live, Сообщество (форум+ресурсы), Сервисы.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/service_helpers.php';
require_once __DIR__ . '/includes/bbcode.php';

$__user = current_user();

// Вход
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_phone'], $_POST['login_password'])) {
    $phone = function_exists('normalize_phone') ? normalize_phone($_POST['login_phone']) : $_POST['login_phone'];
    if ($phone && $_POST['login_password']) {
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
            $stmt->execute([$phone]);
            $user = $stmt->fetch();
            if ($user && password_verify($_POST['login_password'], $user['password_hash'])) {
                login_user((int)$user['id']);
                header('Location: /streamtok.php?tab=' . ($_GET['tab'] ?? 'foryou'));
                exit;
            } else $login_error = 'Неверный номер или пароль';
        } catch (Exception $e) { $login_error = 'Ошибка БД'; }
    } else $login_error = 'Заполните все поля';
}

$showAuth = !$__user;
$tab = $_GET['tab'] ?? 'foryou';
$allowedTabs = ['foryou', 'subscriptions', 'live', 'community', 'services'];
if (!in_array($tab, $allowedTabs)) $tab = 'foryou';
$items = [];
$consentGiven = isset($_COOKIE['consent_given']) && $_COOKIE['consent_given'] === '1';

if ($__user) {
    try {
        // ---- Базовые данные ----
        // Видео
        $stmt = db()->prepare("
            SELECT v.*, c.id as channel_id, c.slug as channel_slug, c.title as channel_title, c.logo_url as channel_logo
            FROM videos v
            JOIN channels c ON c.id = v.channel_id
            WHERE v.status = 'published'
            ORDER BY v.created_at DESC
        ");
        $stmt->execute();
        $videos = $stmt->fetchAll();

        // Каналы с активным источником (LIVE)
        $channels = recommended_channels($__user, null, 60);
        $liveChannels = [];
        foreach ($channels as $ch) {
            $source = resolve_active_source($ch);
            if ($source) {
                $ch['source_url'] = $source['url'];
                $liveChannels[] = $ch;
            }
        }

        // Форум (последние 30)
        $stmt = db()->prepare("
            SELECT t.id, t.title, t.created_at, t.views, t.is_pinned, u.username as author_username,
                   fc.title as category_title,
                   (SELECT COUNT(*) FROM forum_posts WHERE thread_id = t.id AND is_deleted = 0) as posts_count
            FROM forum_threads t
            JOIN users u ON u.id = t.user_id
            JOIN forum_categories fc ON fc.id = t.category_id
            WHERE t.is_deleted = 0
            ORDER BY t.created_at DESC
            LIMIT 30
        ");
        $stmt->execute();
        $forumThreads = $stmt->fetchAll();

        // Ресурсы (опубликованные)
        $stmt = db()->prepare("
            SELECT r.id, r.title, r.summary, r.created_at, r.views, r.slug, u.username as author_username
            FROM resources r
            JOIN users u ON u.id = r.user_id
            WHERE r.status = 'published'
            ORDER BY r.created_at DESC
            LIMIT 30
        ");
        $stmt->execute();
        $resources = $stmt->fetchAll();

        // OAuth-сервисы
        $stmt = db()->query("
            SELECT oa.*, u.username as author_username
            FROM oauth_apps oa
            JOIN users u ON u.id = oa.owner_id
            WHERE oa.is_public_service = 1 AND oa.service_url IS NOT NULL
            ORDER BY oa.id DESC
            LIMIT 30
        ");
        $oauthServices = $stmt->fetchAll();

        // Deployed-сервисы
        deployed_services_ensure_schema();
        $stmt = db()->query("
            SELECT ds.*, u.username as author_username
            FROM deployed_services ds
            JOIN users u ON u.id = ds.user_id
            WHERE ds.is_public = 1 AND ds.status = 'live'
            ORDER BY ds.id DESC
            LIMIT 30
        ");
        $deployedServices = array_map('deployed_service_autostop', $stmt->fetchAll());

        // ---- Формируем универсальный массив всех элементов ----
        $allItems = [];

        // Видео
        foreach ($videos as $v) {
            $allItems[] = [
                'type' => 'video',
                'id' => $v['id'],
                'slug' => $v['slug'],
                'title' => $v['title'],
                'description' => $v['description'] ?? '',
                'embed_url' => $v['embed_url'],
                'platform' => $v['platform'],
                'views_count' => (int)$v['views_count'],
                'likes_count' => (int)($v['likes_count'] ?? 0),
                'comments_count' => (int)$v['comments_count'],
                'created_at' => $v['created_at'],
                'channel_id' => (int)$v['channel_id'],
                'channel_slug' => $v['channel_slug'],
                'channel_title' => $v['channel_title'],
                'author_username' => $v['channel_title'],
                'link' => '/video.php?slug=' . urlencode($v['slug']),
            ];
        }

        // Каналы (LIVE)
        foreach ($liveChannels as $ch) {
            $allItems[] = [
                'type' => 'channel',
                'id' => $ch['id'],
                'slug' => $ch['slug'],
                'title' => $ch['title'],
                'description' => $ch['description'] ?? '',
                'embed_url' => '',
                'platform' => '',
                'views_count' => (int)$ch['views'],
                'likes_count' => 0,
                'comments_count' => 0,
                'created_at' => $ch['created_at'],
                'channel_id' => (int)$ch['id'],
                'channel_slug' => $ch['slug'],
                'channel_title' => $ch['title'],
                'source_url' => $ch['source_url'],
                'author_username' => $ch['title'],
                'link' => '/channel.php?slug=' . urlencode($ch['slug']),
            ];
        }

        // Форум
        foreach ($forumThreads as $th) {
            $allItems[] = [
                'type' => 'forum_thread',
                'id' => $th['id'],
                'slug' => 'thread_' . $th['id'],
                'title' => $th['title'],
                'description' => 'Тема в разделе ' . $th['category_title'] . ' · ответов: ' . $th['posts_count'],
                'embed_url' => '',
                'platform' => '',
                'views_count' => (int)$th['views'],
                'likes_count' => 0,
                'comments_count' => (int)$th['posts_count'],
                'created_at' => $th['created_at'],
                'channel_id' => 0,
                'channel_slug' => '',
                'channel_title' => '',
                'author_username' => $th['author_username'],
                'link' => '/forum_thread.php?id=' . $th['id'],
            ];
        }

        // Ресурсы
        foreach ($resources as $res) {
            $allItems[] = [
                'type' => 'resource',
                'id' => $res['id'],
                'slug' => $res['slug'],
                'title' => $res['title'],
                'description' => $res['summary'] ?? '',
                'embed_url' => '',
                'platform' => '',
                'views_count' => (int)$res['views'],
                'likes_count' => 0,
                'comments_count' => 0,
                'created_at' => $res['created_at'],
                'channel_id' => 0,
                'channel_slug' => '',
                'channel_title' => '',
                'author_username' => $res['author_username'],
                'link' => '/resource.php?slug=' . urlencode($res['slug']),
            ];
        }

        // OAuth-сервисы
        foreach ($oauthServices as $s) {
            $allItems[] = [
                'type' => 'oauth_service',
                'id' => $s['id'],
                'slug' => $s['client_id'],
                'title' => $s['name'],
                'description' => $s['description'] ?? '',
                'embed_url' => '',
                'platform' => '',
                'views_count' => 0,
                'likes_count' => 0,
                'comments_count' => 0,
                'created_at' => $s['created_at'] ?? date('Y-m-d H:i:s'),
                'channel_id' => 0,
                'channel_slug' => '',
                'channel_title' => '',
                'author_username' => $s['author_username'],
                'link' => '/oauth2/authorize.php?client_id=' . urlencode($s['client_id']),
                'logo_url' => $s['logo_url'] ?: '/assets/img/avatar-placeholder.png',
            ];
        }

        // Deployed-сервисы
        foreach ($deployedServices as $s) {
            $suspended = !empty($s['suspended']);
            $allItems[] = [
                'type' => 'deployed_service',
                'id' => $s['id'],
                'slug' => $s['slug'],
                'title' => $s['name'],
                'description' => $s['description'] ?? '',
                'embed_url' => '',
                'platform' => '',
                'views_count' => 0,
                'likes_count' => 0,
                'comments_count' => 0,
                'created_at' => $s['created_at'] ?? date('Y-m-d H:i:s'),
                'channel_id' => 0,
                'channel_slug' => '',
                'channel_title' => '',
                'author_username' => $s['author_username'],
                'link' => $suspended ? '#' : '/s.php?slug=' . urlencode($s['slug']),
                'suspended' => $suspended,
                'suspended_reason' => $s['suspended_reason'] ?? '',
            ];
        }

        // ---- Фильтрация по вкладкам ----
        if ($tab === 'foryou') {
            $items = $allItems;
        } elseif ($tab === 'subscriptions') {
            // Только избранное (видео и каналы)
            $stmt = db()->prepare('SELECT video_id FROM video_favorites WHERE user_id = ?');
            $stmt->execute([$__user['id']]);
            $favVideoIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt = db()->prepare('SELECT channel_id FROM favorites WHERE user_id = ?');
            $stmt->execute([$__user['id']]);
            $favChannelIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $items = array_filter($allItems, function($item) use ($favVideoIds, $favChannelIds) {
                if ($item['type'] === 'video') return in_array($item['id'], $favVideoIds);
                if ($item['type'] === 'channel') return in_array($item['id'], $favChannelIds);
                return false;
            });
            $items = array_values($items);
        } elseif ($tab === 'live') {
            // Только каналы с источником
            $items = array_filter($allItems, function($item) {
                return $item['type'] === 'channel' && !empty($item['source_url']);
            });
            $items = array_values($items);
        } elseif ($tab === 'community') {
            // ТОЛЬКО ФОРУМ И РЕСУРСЫ — БЕЗ КАНАЛОВ!
            $items = array_filter($allItems, function($item) {
                return in_array($item['type'], ['forum_thread', 'resource']);
            });
            $items = array_values($items);
        } elseif ($tab === 'services') {
            // Только OAuth и Deployed
            $items = array_filter($allItems, function($item) {
                return in_array($item['type'], ['oauth_service', 'deployed_service']);
            });
            $items = array_values($items);
        }

        // ---- Персонализированное ранжирование (только для "Для вас") ----
        if ($tab === 'foryou') {
            $viewedVideos = isset($_COOKIE['viewed_videos']) ? array_filter(array_map('intval', explode(',', $_COOKIE['viewed_videos']))) : [];
            $viewedChannels = isset($_COOKIE['viewed_channels']) ? array_filter(array_map('intval', explode(',', $_COOKIE['viewed_channels']))) : [];
            $viewedForum = isset($_COOKIE['viewed_forum']) ? array_filter(array_map('intval', explode(',', $_COOKIE['viewed_forum']))) : [];
            $viewedResources = isset($_COOKIE['viewed_resources']) ? array_filter(array_map('intval', explode(',', $_COOKIE['viewed_resources']))) : [];

            $stmt = db()->prepare('SELECT video_id FROM video_likes WHERE user_id = ?');
            $stmt->execute([$__user['id']]);
            $userLikedVideoIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt = db()->prepare('SELECT channel_id FROM channel_likes WHERE user_id = ?');
            $stmt->execute([$__user['id']]);
            $userLikedChannelIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt = db()->prepare('SELECT video_id FROM video_favorites WHERE user_id = ?');
            $stmt->execute([$__user['id']]);
            $userFavVideoIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt = db()->prepare('SELECT channel_id FROM favorites WHERE user_id = ?');
            $stmt->execute([$__user['id']]);
            $userFavChannelIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $score = function($item) use ($viewedVideos, $viewedChannels, $viewedForum, $viewedResources,
                                    $userLikedVideoIds, $userLikedChannelIds, $userFavVideoIds, $userFavChannelIds) {
                $id = (int)$item['id'];
                $type = $item['type'];
                $views = (int)($item['views_count'] ?? 0);
                $likes = (int)($item['likes_count'] ?? 0);
                $comments = (int)($item['comments_count'] ?? 0);
                $time = strtotime($item['created_at']) / 86400 / 3650;

                $penalty = 0;
                if ($type === 'video' && in_array($id, $viewedVideos)) $penalty += 10;
                if ($type === 'channel' && in_array($id, $viewedChannels)) $penalty += 10;
                if ($type === 'forum_thread' && in_array($id, $viewedForum)) $penalty += 8;
                if ($type === 'resource' && in_array($id, $viewedResources)) $penalty += 8;

                $bonus = 0;
                if ($type === 'video' && in_array($id, $userLikedVideoIds)) $bonus += 20;
                if ($type === 'channel' && in_array($id, $userLikedChannelIds)) $bonus += 20;
                if ($type === 'video' && in_array($id, $userFavVideoIds)) $bonus += 30;
                if ($type === 'channel' && in_array($id, $userFavChannelIds)) $bonus += 30;
                if ($type === 'video' && in_array($item['channel_id'], $userFavChannelIds)) $bonus += 15;

                return log(1 + $views) + $likes * 0.5 + $comments * 0.3 + $time + $bonus - $penalty;
            };

            usort($items, function($a, $b) use ($score) {
                return $score($b) <=> $score($a);
            });

            // Лёгкое перемешивание для разнообразия
            $shuffleCount = (int)(count($items) * 0.1);
            if ($shuffleCount > 1) {
                $first = array_slice($items, 0, $shuffleCount);
                shuffle($first);
                $items = array_merge($first, array_slice($items, $shuffleCount));
            }
        }

        // ---- Дополняем лайками и избранным для видео/каналов ----
        foreach ($items as &$item) {
            $item['liked'] = false;
            $item['favorited'] = false;
            if ($item['type'] === 'video') {
                $stmt = db()->prepare('SELECT id FROM video_likes WHERE video_id = ? AND user_id = ?');
                $stmt->execute([$item['id'], $__user['id']]);
                $item['liked'] = (bool)$stmt->fetch();
                $stmt = db()->prepare('SELECT id FROM video_favorites WHERE video_id = ? AND user_id = ?');
                $stmt->execute([$item['id'], $__user['id']]);
                $item['favorited'] = (bool)$stmt->fetch();
                $stmt = db()->prepare('SELECT COUNT(*) FROM video_likes WHERE video_id = ?');
                $stmt->execute([$item['id']]);
                $item['likes_count'] = (int)$stmt->fetchColumn();
            } elseif ($item['type'] === 'channel') {
                $stmt = db()->prepare('SELECT id FROM channel_likes WHERE channel_id = ? AND user_id = ?');
                $stmt->execute([$item['id'], $__user['id']]);
                $item['liked'] = (bool)$stmt->fetch();
                $stmt = db()->prepare('SELECT id FROM favorites WHERE channel_id = ? AND user_id = ?');
                $stmt->execute([$item['id'], $__user['id']]);
                $item['favorited'] = (bool)$stmt->fetch();
                $stmt = db()->prepare('SELECT COUNT(*) FROM channel_likes WHERE channel_id = ?');
                $stmt->execute([$item['id']]);
                $item['likes_count'] = (int)$stmt->fetchColumn();
            }
        }
        unset($item);

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
        :root{--bg:#000;--bg2:#1a1a1a;--bg3:#2a2a2a;--text:#fff;--accent:#00f2ea;--red:#ff2d55;--border:#222;}
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:-apple-system,system-ui,sans-serif;background:var(--bg);color:var(--text);overflow:hidden;height:100vh;position:fixed;width:100%;}
        .auth-screen{position:fixed;inset:0;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:1000;padding:20px;}
        .auth-logo{font-size:60px;margin-bottom:20px;}
        .auth-title{font-size:28px;font-weight:700;color:#fff;}
        .auth-subtitle{font-size:14px;opacity:.8;margin-bottom:40px;color:#fff;}
        .auth-form{width:100%;max-width:350px;}
        .auth-input{width:100%;padding:15px;border:none;border-radius:12px;font-size:16px;margin-bottom:15px;background:rgba(255,255,255,.9);color:#000;}
        .auth-btn{width:100%;padding:15px;border:none;border-radius:12px;font-size:16px;font-weight:600;background:#fff;color:#667eea;cursor:pointer;}
        .auth-error{color:#f44;margin-bottom:15px;text-align:center;}
        .auth-link{color:#fff;margin-top:15px;text-align:center;}
        .auth-link a{color:#fff;text-decoration:underline;}
        .app-container{height:100vh;display:flex;flex-direction:column;}
        .tab-nav{display:flex;gap:8px;padding:10px 16px;background:var(--bg);border-bottom:1px solid var(--border);overflow-x:auto;flex-shrink:0;scrollbar-width:none;}
        .tab-nav::-webkit-scrollbar{display:none;}
        .tab-btn{flex-shrink:0;background:none;border:none;color:var(--text);font-size:15px;font-weight:600;opacity:.5;cursor:pointer;padding:6px 14px;border-radius:20px;transition:.3s;white-space:nowrap;}
        .tab-btn.active{opacity:1;background:var(--bg3);}
        .content-area{flex:1;overflow-y:scroll;scroll-snap-type:y mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
        .content-area::-webkit-scrollbar{display:none;}
        .video-item{height:100vh;width:100%;position:relative;scroll-snap-align:start;background:var(--bg);display:flex;align-items:center;justify-content:center;overflow:hidden;}
        .player-container{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#000;}
        .player-container iframe,.player-container video{width:100%;height:100%;border:none;object-fit:contain;}
        .card-content{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;background:var(--bg2);text-align:center;}
        .card-content .type-icon{font-size:48px;margin-bottom:12px;}
        .card-content .card-title{font-size:18px;font-weight:600;margin-bottom:6px;}
        .card-content .card-desc{font-size:14px;color:var(--text-dim);max-width:80%;margin:0 auto;}
        .card-content .card-author{font-size:12px;color:var(--text-dim);margin-top:10px;}
        .video-overlay{position:absolute;bottom:80px;left:0;right:0;padding:20px;background:linear-gradient(transparent,rgba(0,0,0,.7));z-index:10;pointer-events:none;}
        .video-title{font-size:16px;font-weight:600;color:#fff;}
        .video-title a{color:#fff;text-decoration:none;}
        .channel-name{opacity:.7;font-weight:400;font-size:14px;}
        .side-actions{position:absolute;right:10px;bottom:150px;z-index:11;display:flex;flex-direction:column;gap:20px;}
        .action-btn{background:rgba(255,255,255,.15);border:none;width:50px;height:50px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;color:#fff;font-size:20px;backdrop-filter:blur(10px);}
        .action-btn span{font-size:11px;margin-top:2px;}
        .action-btn.liked{color:#ff2d55;background:rgba(255,45,85,.3);}
        .action-btn.favorited{color:#ffcc00;background:rgba(255,204,0,.3);}
        .bottom-nav{height:60px;background:var(--bg);border-top:1px solid var(--border);display:flex;justify-content:space-around;align-items:center;flex-shrink:0;}
        .nav-btn{background:none;border:none;color:var(--text);font-size:24px;cursor:pointer;padding:10px 20px;opacity:.6;transition:.3s;border-radius:50%;}
        .nav-btn.active{opacity:1;color:var(--accent);background:var(--bg3);}
        .badge-live{background:#ff2d55;padding:2px 8px;border-radius:4px;font-size:11px;margin-left:6px;color:#fff;}
        .badge-video{background:#00f2ea;padding:2px 8px;border-radius:4px;font-size:11px;margin-left:6px;color:#000;}
        .badge-forum{background:#ffa500;padding:2px 8px;border-radius:4px;font-size:11px;margin-left:6px;color:#000;}
        .badge-resource{background:#7b68ee;padding:2px 8px;border-radius:4px;font-size:11px;margin-left:6px;color:#fff;}
        .badge-service{background:#1e90ff;padding:2px 8px;border-radius:4px;font-size:11px;margin-left:6px;color:#fff;}
        .empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;color:#666;padding:20px;text-align:center;font-size:18px;}
        .empty-state p:first-child{font-size:18px;margin-bottom:10px;}
        .empty-state p:last-child{font-size:14px;opacity:.7;}
        .loading-placeholder{color:#555;font-size:16px;}
        .suspended-badge{background:#ff4444;padding:2px 8px;border-radius:4px;font-size:11px;margin-left:6px;color:#fff;}
        .consent-banner{position:fixed;bottom:0;left:0;right:0;background:var(--bg2);border-top:1px solid var(--border);padding:16px 20px;z-index:9999;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;font-size:14px;}
        .consent-banner p{margin:0;flex:1;}
        .consent-banner .btn-group{display:flex;gap:10px;}
        .consent-banner .btn{padding:8px 20px;border:none;border-radius:20px;cursor:pointer;font-weight:600;}
        .btn-primary-consent{background:var(--accent);color:#000;}
        .btn-outline-consent{background:transparent;border:1px solid var(--border);color:var(--text);}
        @media(max-width:768px){.side-actions{right:8px;bottom:100px;gap:12px;}.action-btn{width:44px;height:44px;font-size:18px;}.video-overlay{bottom:60px;padding:15px;}.video-title{font-size:14px;}.tab-nav{gap:6px;padding:8px 12px;}.tab-btn{font-size:13px;padding:5px 10px;}.consent-banner{flex-direction:column;align-items:stretch;text-align:center;}}
    </style>
</head>
<body>

<?php if ($showAuth): ?>
<div class="auth-screen">
    <div class="auth-logo">📱</div>
    <div class="auth-title">StreamTok</div>
    <div class="auth-subtitle">Войдите с номером телефона</div>
    <form class="auth-form" method="POST" action="/streamtok?tab=<?= urlencode($tab) ?>">
        <?php if (isset($login_error)): ?><div class="auth-error"><?= htmlspecialchars($login_error) ?></div><?php endif; ?>
        <input type="tel" class="auth-input" name="login_phone" placeholder="+7 999 123 4567" required>
        <input type="password" class="auth-input" name="login_password" placeholder="Пароль" required>
        <button class="auth-btn" type="submit">Войти</button>
        <div class="auth-link">Нет аккаунта? <a href="/auth/register.php">Зарегистрироваться</a></div>
    </form>
</div>
<?php else: ?>
<div class="app-container">
    <!-- Верхние табы -->
    <div class="tab-nav">
        <button class="tab-btn <?= $tab==='foryou'?'active':'' ?>" onclick="changeTab('foryou')">Для вас</button>
        <button class="tab-btn <?= $tab==='subscriptions'?'active':'' ?>" onclick="changeTab('subscriptions')">Подписки</button>
        <button class="tab-btn <?= $tab==='live'?'active':'' ?>" onclick="changeTab('live')">Live</button>
        <button class="tab-btn <?= $tab==='community'?'active':'' ?>" onclick="changeTab('community')">Сообщество</button>
        <button class="tab-btn <?= $tab==='services'?'active':'' ?>" onclick="changeTab('services')">Сервисы</button>
    </div>

    <div class="content-area" id="contentArea">
        <?php if (isset($dbError)): ?>
            <div class="empty-state"><p>⚠️ Ошибка</p><p><?= htmlspecialchars($dbError) ?></p></div>
        <?php elseif (empty($items)): ?>
            <div class="empty-state">
                <p>😕 Ничего не найдено</p>
                <p><?php
                    if ($tab === 'foryou') echo 'Попробуйте подписаться на каналы или позже заглянуть';
                    elseif ($tab === 'subscriptions') echo 'Вы пока ни на что не подписаны';
                    elseif ($tab === 'live') echo 'Сейчас нет активных эфиров';
                    elseif ($tab === 'community') echo 'В сообществе пока нет тем форума или ресурсов';
                    else echo 'Сервисы ещё не добавлены';
                ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($items as $item):
                $hasPlayer = in_array($item['type'], ['video', 'channel']) && !empty($item['embed_url'] ?? $item['source_url'] ?? null);
                $isSuspended = ($item['type'] === 'deployed_service' && !empty($item['suspended']));
                $badge = '';
                if ($item['type'] === 'video') $badge = '<span class="badge-video">Видео</span>';
                elseif ($item['type'] === 'channel') $badge = '<span class="badge-live">🔴 LIVE</span>';
                elseif ($item['type'] === 'forum_thread') $badge = '<span class="badge-forum">Форум</span>';
                elseif ($item['type'] === 'resource') $badge = '<span class="badge-resource">Ресурс</span>';
                elseif ($item['type'] === 'oauth_service' || $item['type'] === 'deployed_service') $badge = '<span class="badge-service">Сервис</span>';
                $iconMap = [
                    'video' => '🎬',
                    'channel' => '📡',
                    'forum_thread' => '💬',
                    'resource' => '📦',
                    'oauth_service' => '🔑',
                    'deployed_service' => '🚀',
                ];
                $icon = $iconMap[$item['type']] ?? '📄';
            ?>
                <div class="video-item" data-type="<?= $item['type'] ?>" data-id="<?= $item['id'] ?>"
                     data-slug="<?= $item['slug'] ?? '' ?>"
                     data-embed-url="<?= htmlspecialchars($item['embed_url'] ?? '') ?>"
                     data-platform="<?= htmlspecialchars($item['platform'] ?? '') ?>"
                     data-source-url="<?= htmlspecialchars($item['source_url'] ?? '') ?>"
                     data-link="<?= htmlspecialchars($item['link'] ?? '') ?>">
                    <div class="player-container" id="player-<?= $item['type'].'-'.$item['id'] ?>">
                        <?php if ($hasPlayer): ?>
                            <div class="loading-placeholder">⏳ Загрузка...</div>
                        <?php else: ?>
                            <div class="card-content">
                                <div class="type-icon"><?= $icon ?></div>
                                <div class="card-title"><?= htmlspecialchars($item['title']) ?></div>
                                <div class="card-desc"><?= htmlspecialchars(mb_substr($item['description'] ?? '', 0, 120)) ?></div>
                                <div class="card-author">от <?= htmlspecialchars($item['author_username'] ?? '') ?> · <?= htmlspecialchars($item['created_at'] ?? '') ?></div>
                                <?php if ($isSuspended): ?>
                                    <div class="suspended-badge">Услуга окончена</div>
                                    <small style="color:var(--text-dim);"><?= htmlspecialchars($item['suspended_reason'] ?? 'Продлите подписку') ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($hasPlayer): ?>
                    <div class="video-overlay">
                        <div class="video-title">
                            <?php if ($item['type'] === 'video'): ?>
                                <a href="/video.php?slug=<?= urlencode($item['slug']) ?>" target="_blank"><?= htmlspecialchars($item['title']) ?></a>
                                <span class="channel-name">— <a href="/channel.php?slug=<?= urlencode($item['channel_slug']) ?>" target="_blank" style="color:#fff;opacity:.7;"><?= htmlspecialchars($item['channel_title']) ?></a></span>
                                <?= $badge ?>
                            <?php else: ?>
                                <a href="/channel.php?slug=<?= urlencode($item['slug']) ?>" target="_blank"><?= htmlspecialchars($item['title']) ?></a>
                                <span class="channel-name">— канал</span>
                                <?= $badge ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Для карточек тоже показываем оверлей с названием -->
                    <div class="video-overlay">
                        <div class="video-title">
                            <?= htmlspecialchars($item['title']) ?>
                            <?= $badge ?>
                            <span class="channel-name">— <?= htmlspecialchars($item['author_username'] ?? '') ?></span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="side-actions">
                        <?php if (in_array($item['type'], ['video', 'channel'])): ?>
                            <button class="action-btn <?= $item['liked']?'liked':'' ?>" onclick="toggleLike(this,'<?= $item['type'] ?>',<?= $item['id'] ?>)">
                                ♥ <span><?= (int)$item['likes_count'] ?></span>
                            </button>
                            <button class="action-btn <?= $item['favorited']?'favorited':'' ?>" onclick="toggleFavorite(this,'<?= $item['type'] ?>',<?= $item['id'] ?>)">
                                <?= $item['favorited'] ? '★' : '☆' ?>
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($item['link']) && $item['link'] !== '#'): ?>
                            <button class="action-btn" onclick="window.open('<?= htmlspecialchars($item['link']) ?>','_blank')">🔗</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Нижняя навигация -->
    <div class="bottom-nav">
        <button class="nav-btn <?= $tab==='foryou'?'active':'' ?>" onclick="changeTab('foryou')">🏠</button>
        <button class="nav-btn <?= $tab==='subscriptions'?'active':'' ?>" onclick="changeTab('subscriptions')">❤️</button>
        <button class="nav-btn <?= $tab==='live'?'active':'' ?>" onclick="changeTab('live')">📡</button>
        <button class="nav-btn <?= $tab==='community'?'active':'' ?>" onclick="changeTab('community')">👥</button>
        <button class="nav-btn <?= $tab==='services'?'active':'' ?>" onclick="changeTab('services')">🧩</button>
        <button class="nav-btn" onclick="location.href='/dashboard.php'">👤</button>
    </div>
</div>

<?php if (!$consentGiven): ?>
<div id="consentBanner" class="consent-banner">
    <p>🍪 Мы используем куки для персонализации. Продолжая, вы соглашаетесь.</p>
    <div class="btn-group">
        <button class="btn btn-primary-consent" onclick="acceptConsent()">Принять</button>
        <button class="btn btn-outline-consent" onclick="declineConsent()">Отказаться</button>
    </div>
</div>
<script>
function acceptConsent(){ document.cookie="consent_given=1; path=/; max-age="+(365*24*60*60); document.getElementById('consentBanner').style.display='none'; }
function declineConsent(){ document.cookie="consent_given=0; path=/; max-age="+(365*24*60*60); document.getElementById('consentBanner').style.display='none'; }
</script>
<?php endif; ?>
<?php endif; ?>

<script>
const currentUserId = <?= $__user ? (int)$__user['id'] : 'null' ?>;
function changeTab(tab){ location.href='/streamtok.php?tab='+tab; }

async function toggleLike(btn,type,id){
    if(!currentUserId) return location.href='/auth/login.php';
    const url = type==='video'?'/video_like.php':'/channel_like.php';
    try {
        const resp = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({video_id:id,channel_id:id})});
        const data = await resp.json();
        if(data.ok){ btn.classList.toggle('liked',data.liked); const span=btn.querySelector('span'); if(span) span.textContent=data.count||0; }
    } catch(e){ console.error(e); }
}
async function toggleFavorite(btn,type,id){
    if(!currentUserId) return location.href='/auth/login.php';
    const url = type==='video'?'/video_favorite.php':'/channel_favorite.php';
    try {
        const resp = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({video_id:id,channel_id:id})});
        const data = await resp.json();
        if(data.ok){ btn.classList.toggle('favorited',data.favorited); btn.textContent=data.favorited?'★':'☆'; }
    } catch(e){ console.error(e); }
}

let loadedPlayers = new Set();
function loadPlayer(el){
    const c = el.querySelector('.player-container'); if(!c) return;
    const id = c.id; if(loadedPlayers.has(id)) return; loadedPlayers.add(id);
    const type=el.dataset.type, slug=el.dataset.slug, embedUrl=el.dataset.embedUrl, platform=el.dataset.platform, sourceUrl=el.dataset.sourceUrl;
    if(!embedUrl && !sourceUrl) return;
    let html='';
    if(type==='video'){
        if(platform==='mp4') html=`<video controls autoplay muted playsinline loop><source src="${embedUrl}" type="video/mp4"></video>`;
        else if(platform==='m3u8'){
            const vid='hls-'+id;
            html=`<video id="${vid}" controls autoplay muted playsinline loop></video><script>
                (function(){ var v=document.getElementById("${vid}"); if(window.Hls&&Hls.isSupported()){var h=new Hls();h.loadSource("${embedUrl}");h.attachMedia(v);}else v.src="${embedUrl}"; v.play().catch(()=>{}); })();
            <\/script>`;
        } else html=`<iframe src="${embedUrl}" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
    } else if(type==='channel' && sourceUrl){
        html=`<iframe src="/embed.php?slug=${slug}&autoplay=1&mute=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
    } else html=`<div style="color:#555;font-size:18px;display:flex;align-items:center;justify-content:center;height:100%;">Сейчас нет эфира</div>`;
    c.innerHTML = html;
}
function unloadPlayer(el){
    const c = el.querySelector('.player-container'); if(!c) return;
    const id = c.id; if(!loadedPlayers.has(id)) return; loadedPlayers.delete(id);
    c.innerHTML = '<div class="loading-placeholder">⏳ Загрузка...</div>';
}
const observer = new IntersectionObserver((entries)=>{
    entries.forEach(entry=>{
        const el = entry.target;
        if(entry.isIntersecting){
            loadPlayer(el);
            if(document.cookie.indexOf('consent_given=1')!==-1){
                const type=el.dataset.type, id=el.dataset.id;
                if(type && id){
                    let cookieName = '';
                    if(type==='video') cookieName='viewed_videos';
                    else if(type==='channel') cookieName='viewed_channels';
                    else if(type==='forum_thread') cookieName='viewed_forum';
                    else if(type==='resource') cookieName='viewed_resources';
                    else return;
                    let cur = getCookie(cookieName);
                    let ids = cur ? cur.split(',').map(Number) : [];
                    if(!ids.includes(Number(id))){
                        ids.push(Number(id));
                        if(ids.length>100) ids.shift();
                        document.cookie = cookieName+'='+ids.join(',')+'; path=/; max-age='+(30*24*60*60);
                    }
                }
            }
        } else {
            unloadPlayer(el);
        }
    });
},{threshold:.1, rootMargin:'100px'});
function getCookie(n){ const v="; "+document.cookie; const p=v.split("; "+n+"="); if(p.length===2) return p.pop().split(";").shift(); return ""; }
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.video-item').forEach(el=>observer.observe(el));
    const first=document.querySelector('.video-item');
    if(first){ const rect=first.getBoundingClientRect(); if(rect.top<window.innerHeight && rect.bottom>0) loadPlayer(first); }
});
</script>
</body>
</html>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
