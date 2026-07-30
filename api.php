<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/api_auth.php';
require_once __DIR__ . '/includes/gamification.php'; // для ранга
require_once __DIR__ . '/includes/resources.php';   // для resource_url()

header('Content-Type: application/json; charset=utf-8');

$apiState = api_guard();
if (!$apiState['ok']) exit; // 401 или 503

$type = $_GET['type'] ?? '';

// Если type не передан – выдаём справку
if (empty($type)) {
    echo json_encode([
        'ok' => true,
        'authenticated' => $apiState['authenticated'],
        'warning' => $apiState['warning'],
        'available_types' => [
            'videos'      => 'Список видео (параметры: limit)',
            'channels'    => 'ТВ/радио каналы (type_filter=tv|radio, limit)',
            'forum'       => 'Категории форума или темы (category_id=число, limit)',
            'resources'   => 'Опубликованные ресурсы (limit)',
            'broadcast'   => 'Каналы-рассылки (q=поиск, limit)',
            'profile'     => 'Профиль пользователя (username=ник)'
        ],
        'example' => '/api.php?type=videos&limit=10'
    ]);
    exit;
}

$response = [
    'ok' => true,
    'authenticated' => $apiState['authenticated'],
    'warning' => $apiState['warning'],
    'type' => $type,
    'data' => null,
];

try {
    switch ($type) {
        case 'videos':
            $response['data'] = getVideos($_GET);
            break;
        case 'channels':
            $response['data'] = getChannels($_GET);
            break;
        case 'forum':
            $response['data'] = getForum($_GET);
            break;
        case 'resources':
            $response['data'] = getResources($_GET);
            break;
        case 'broadcast':
            $response['data'] = getBroadcastChannels($_GET);
            break;
        case 'profile':
            $response['data'] = getProfile($_GET, $apiState);
            break;
        default:
            http_response_code(400);
            $response['ok'] = false;
            $response['error'] = 'Unknown type parameter. Allowed: videos, channels, forum, resources, broadcast, profile.';
            echo json_encode($response);
            exit;
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ---------- Функции-обработчики с добавленными ссылками ----------

function getVideos($params) {
    $limit = min(50, max(1, (int)($params['limit'] ?? 20)));
    $stmt = db()->prepare(
        "SELECT v.slug, v.title, v.thumbnail_url, v.views_count, 
                c.title AS channel_title, c.slug AS channel_slug
         FROM videos v 
         JOIN channels c ON c.id = v.channel_id
         WHERE v.status = 'published' 
         ORDER BY v.created_at DESC LIMIT ?"
    );
    $stmt->execute([$limit]);
    $videos = $stmt->fetchAll();
    foreach ($videos as &$v) {
        $v['watch_url'] = '/watch.php?slug=' . $v['slug'];
        $v['channel_url'] = '/channel.php?slug=' . $v['channel_slug'];
    }
    return $videos;
}

function getChannels($params) {
    $limit = min(50, max(1, (int)($params['limit'] ?? 20)));
    $type = $params['type_filter'] ?? null;
    if ($type && !in_array($type, ['tv', 'radio'])) $type = null;

    $sql = "SELECT id, slug, title, logo_url, type, views, description
            FROM channels WHERE status = 'approved'";
    $args = [];
    if ($type) {
        $sql .= " AND type = ?";
        $args[] = $type;
    }
    $sql .= " ORDER BY views DESC, created_at DESC LIMIT ?";
    $args[] = $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $channels = $stmt->fetchAll();
    foreach ($channels as &$c) {
        $c['channel_url'] = '/channel.php?slug=' . $c['slug'];
        // Для просмотра в плеере (Shorts-режим) тоже можно добавить:
        $c['player_url'] = '/embed.php?slug=' . $c['slug'];
    }
    return $channels;
}

function getForum($params) {
    $categoryId = isset($params['category_id']) ? (int)$params['category_id'] : 0;
    if ($categoryId > 0) {
        $limit = min(50, max(1, (int)($params['limit'] ?? 20)));
        $stmt = db()->prepare(
            "SELECT t.id, t.title, t.created_at, t.last_post_at,
                    u.username AS author_username,
                    (SELECT COUNT(*) FROM forum_posts WHERE thread_id = t.id AND is_deleted = 0) AS post_count
             FROM forum_threads t
             JOIN users u ON u.id = t.user_id
             WHERE t.category_id = ? AND t.is_deleted = 0
             ORDER BY t.last_post_at DESC LIMIT ?"
        );
        $stmt->execute([$categoryId, $limit]);
        $threads = $stmt->fetchAll();
        foreach ($threads as &$t) {
            $t['thread_url'] = '/forum_thread.php?id=' . $t['id'];
        }
        return [
            'category_id' => $categoryId,
            'threads' => $threads,
        ];
    } else {
        $categories = db()->query(
            "SELECT fc.id, fc.title, fc.description,
                    (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = fc.id AND t.is_deleted = 0) AS thread_count,
                    (SELECT COUNT(*) FROM forum_posts p
                     JOIN forum_threads t ON t.id = p.thread_id
                     WHERE t.category_id = fc.id AND t.is_deleted = 0 AND p.is_deleted = 0) AS post_count
             FROM forum_categories fc
             ORDER BY fc.sort_order ASC, fc.id ASC"
        )->fetchAll();

        foreach ($categories as &$cat) {
            $stmt = db()->prepare(
                "SELECT t.id, t.title, t.last_post_at, u.username
                 FROM forum_threads t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.category_id = ? AND t.is_deleted = 0
                 ORDER BY t.last_post_at DESC LIMIT 1"
            );
            $stmt->execute([$cat['id']]);
            $last = $stmt->fetch();
            if ($last) {
                $last['thread_url'] = '/forum_thread.php?id=' . $last['id'];
                $cat['last_thread'] = $last;
            } else {
                $cat['last_thread'] = null;
            }
            $cat['category_url'] = '/forum_category.php?id=' . $cat['id'];
        }
        unset($cat);
        return ['categories' => $categories];
    }
}

function getResources($params) {
    $limit = min(50, max(1, (int)($params['limit'] ?? 20)));
    $stmt = db()->prepare(
        "SELECT r.id, r.title, r.summary, r.url, r.tags, r.created_at, u.username
         FROM resources r
         JOIN users u ON u.id = r.user_id
         WHERE r.status = 'published'
         ORDER BY r.created_at DESC LIMIT ?"
    );
    $stmt->execute([$limit]);
    $resources = $stmt->fetchAll();
    foreach ($resources as &$r) {
        $r['resource_url'] = resource_url($r); // используем существующую функцию
    }
    return $resources;
}

function getBroadcastChannels($params) {
    $limit = min(50, max(1, (int)($params['limit'] ?? 20)));
    $q = trim($params['q'] ?? '');
    if ($q !== '') {
        $stmt = db()->prepare(
            "SELECT bc.id, bc.slug, bc.title, bc.description, bc.avatar_url, bc.created_at,
                    (SELECT COUNT(*) FROM broadcast_subscribers WHERE channel_id = bc.id) AS subscriber_count
             FROM broadcast_channels bc
             WHERE bc.title LIKE ?
             ORDER BY subscriber_count DESC, bc.id DESC LIMIT ?"
        );
        $stmt->execute(['%' . $q . '%', $limit]);
    } else {
        $stmt = db()->prepare(
            "SELECT bc.id, bc.slug, bc.title, bc.description, bc.avatar_url, bc.created_at,
                    (SELECT COUNT(*) FROM broadcast_subscribers WHERE channel_id = bc.id) AS subscriber_count
             FROM broadcast_channels bc
             ORDER BY subscriber_count DESC, bc.id DESC LIMIT ?"
        );
        $stmt->execute([$limit]);
    }
    $channels = $stmt->fetchAll();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId) {
        $subStmt = db()->prepare('SELECT channel_id FROM broadcast_subscribers WHERE user_id = ?');
        $subStmt->execute([$userId]);
        $subIds = array_column($subStmt->fetchAll(), 'channel_id');
        foreach ($channels as &$c) {
            $c['is_subscribed'] = in_array($c['id'], $subIds);
            $c['channel_url'] = '/broadcast_channel.php?slug=' . $c['slug'];
        }
        unset($c);
    } else {
        foreach ($channels as &$c) {
            $c['channel_url'] = '/broadcast_channel.php?slug=' . $c['slug'];
        }
    }
    return $channels;
}

function getProfile($params, $apiState) {
    $username = trim($params['username'] ?? '');
    if (!$username) {
        throw new Exception('Parameter "username" is required for profile type.');
    }
    $stmt = db()->prepare('SELECT id, username, avatar, gravatar_email, role, created_at, is_verified, is_banned, xp FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        throw new Exception('User not found.');
    }

    $rank = get_rank_for_xp((int)$user['xp']);
    $user['rank_title'] = $rank['title'];

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if (!$currentUserId && isset($apiState['user_id'])) {
        $currentUserId = (int)$apiState['user_id'];
    }
    $isOwn = ($currentUserId && $currentUserId === (int)$user['id']);

    // Каналы пользователя
    if ($isOwn) {
        $stmt = db()->prepare("SELECT * FROM channels WHERE owner_id = ? AND status = 'approved' ORDER BY id DESC");
    } else {
        $stmt = db()->prepare("SELECT * FROM channels WHERE owner_id = ? AND status = 'approved' AND is_public = 1 ORDER BY id DESC");
    }
    $stmt->execute([$user['id']]);
    $channels = $stmt->fetchAll();
    foreach ($channels as &$c) {
        $c['channel_url'] = '/channel.php?slug=' . $c['slug'];
    }

    // Broadcast-каналы пользователя
    $stmt = db()->prepare("SELECT * FROM broadcast_channels WHERE owner_id = ? ORDER BY id DESC");
    $stmt->execute([$user['id']]);
    $broadcastChannels = $stmt->fetchAll();
    foreach ($broadcastChannels as &$bc) {
        $bc['channel_url'] = '/broadcast_channel.php?slug=' . $bc['slug'];
    }

    $avatarUrl = user_avatar_url($user, 192);

    return [
        'user' => $user,
        'avatar_url' => $avatarUrl,
        'is_own_profile' => $isOwn,
        'channels' => $channels,
        'broadcast_channels' => $broadcastChannels,
    ];
}