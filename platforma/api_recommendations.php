<?php
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';

$limit = min(40, max(1, (int)($_GET['limit'] ?? 24)));
if (session_status() === PHP_SESSION_NONE) @session_start();
$user_id = (int)($_GET['user_id'] ?? $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));

$pdo = pl_pdo();
$site = pl_site_web();
$items = [];

if ($pdo) {
    $chTable = pl_find_table($pdo, ['channels', 'broadcast_channels']);
    $likeTable = pl_find_table($pdo, ['channel_likes', 'favorites']);
    $progTable = pl_find_table($pdo, ['video_watch_progress']);
    $vidTable = pl_find_table($pdo, ['stream_videos', 'videos']);

    // 1) лайкнутые каналы
    if ($likeTable && $chTable && $user_id > 0) {
        $lcols = pl_columns($pdo, $likeTable);
        $ccols = pl_columns($pdo, $chTable);
        $uidCol = pl_pick_col($lcols, ['user_id', 'uid']);
        $cidCol = pl_pick_col($lcols, ['channel_id', 'cid', 'item_id', 'target_id']);
        $idCol = pl_pick_col($ccols, ['id']);
        if ($uidCol && $cidCol && $idCol) {
            try {
                $st = $pdo->prepare("SELECT c.* FROM `$chTable` c INNER JOIN `$likeTable` l ON l.`$cidCol` = c.`$idCol` WHERE l.`$uidCol` = ? LIMIT " . (int)$limit);
                $st->execute([$user_id]);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $items[] = pl_normalize_channel($row, $ccols, $site);
                }
            } catch (Throwable $e) {}
        }
    }

    // 2) continue watching / progress → связанные каналы или видео
    if ($progTable && $user_id > 0 && count($items) < $limit) {
        $pcols = pl_columns($pdo, $progTable);
        $uidCol = pl_pick_col($pcols, ['user_id', 'uid']);
        $vidCol = pl_pick_col($pcols, ['video_id', 'stream_video_id', 'content_id']);
        if ($uidCol && $vidCol && $vidTable) {
            $vcols = pl_columns($pdo, $vidTable);
            $vidId = pl_pick_col($vcols, ['id']);
            $vTitle = pl_pick_col($vcols, ['title', 'name']);
            $vThumb = pl_pick_col($vcols, ['thumbnail', 'thumb', 'poster', 'cover', 'logo_url']);
            try {
                $sql = "SELECT v.* FROM `$vidTable` v
                        INNER JOIN `$progTable` p ON p.`$vidCol` = v.`$vidId`
                        WHERE p.`$uidCol` = ?
                        ORDER BY p.id DESC LIMIT 10";
                $st = $pdo->prepare($sql);
                $st->execute([$user_id]);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $id = $vidId ? ($row[$vidId] ?? '') : '';
                    $items[] = [
                        'id' => $id,
                        'title' => $vTitle ? (string)($row[$vTitle] ?? 'Видео') : 'Видео',
                        'type' => 'channel',
                        'meta' => 'Продолжить просмотр',
                        'live' => false,
                        'thumb' => $vThumb ? (string)($row[$vThumb] ?? '') : '',
                        'embed' => $site . '/video.php?id=' . rawurlencode((string)$id),
                        'embed_alt' => [
                            $site . '/embed.php?video=' . rawurlencode((string)$id),
                            $site . '/video.php?id=' . rawurlencode((string)$id),
                        ],
                    ];
                }
            } catch (Throwable $e) {}
        }
    }

    // 3) популярные каналы
    if ($chTable && count($items) < $limit) {
        $cols = pl_columns($pdo, $chTable);
        $liveCol = pl_pick_col($cols, ['last_stream_live', 'is_live']);
        $viewCol = pl_pick_col($cols, ['views', 'viewers']);
        $publicCol = pl_pick_col($cols, ['is_public']);
        $orderParts = [];
        if ($liveCol) $orderParts[] = "`$liveCol` DESC";
        if ($viewCol) $orderParts[] = "`$viewCol` DESC";
        $order = $orderParts ? implode(', ', $orderParts) : 'id DESC';
        $where = '1=1';
        if ($publicCol) $where .= " AND (`$publicCol` = 1 OR `$publicCol` = '1' OR `$publicCol` IS NULL)";
        try {
            $st = $pdo->query("SELECT * FROM `$chTable` WHERE $where ORDER BY $order LIMIT " . (int)$limit);
            $seen = [];
            foreach ($items as $it) $seen[(string)$it['id']] = true;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $n = pl_normalize_channel($row, $cols, $site);
                if (isset($seen[(string)$n['id']])) continue;
                $items[] = $n;
                $seen[(string)$n['id']] = true;
                if (count($items) >= $limit) break;
            }
        } catch (Throwable $e) {}
    }

    // 4) свежие темы форума в рекомендации
    if (count($items) < $limit) {
        $tTable = pl_find_table($pdo, ['forum_threads']);
        if ($tTable) {
            $tcols = pl_columns($pdo, $tTable);
            $idCol = pl_pick_col($tcols, ['id']);
            $titleCol = pl_pick_col($tcols, ['title', 'subject', 'name']);
            try {
                $st = $pdo->query("SELECT * FROM `$tTable` ORDER BY " . ($idCol ? "`$idCol` DESC" : '1') . " LIMIT 8");
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $id = $idCol ? ($row[$idCol] ?? '') : '';
                    $items[] = [
                        'id' => $id,
                        'title' => $titleCol ? (string)($row[$titleCol] ?? 'Тема') : 'Тема',
                        'type' => 'forum',
                        'meta' => 'Форум',
                        'live' => false,
                        'thumb' => '',
                        'embed' => $site . '/forum_thread.php?id=' . rawurlencode((string)$id),
                    ];
                }
            } catch (Throwable $e) {}
        }
    }
}

$seen = [];
$out = [];
foreach ($items as $it) {
    $k = ($it['type'] ?? '') . ':' . ($it['id'] ?? $it['title']);
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $out[] = $it;
}
pl_json(array_slice(array_values($out), 0, $limit));
