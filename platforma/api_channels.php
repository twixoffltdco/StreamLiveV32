<?php
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';

$type = strtolower((string)($_GET['type'] ?? 'all'));
$limit = min(80, max(1, (int)($_GET['limit'] ?? 50)));
$debug = isset($_GET['debug']);

$pdo = pl_pdo();
$site = pl_site_web();
$items = [];
$info = ['pdo' => $pdo ? 'ok' : 'null', 'table' => null, 'error' => null];

if ($pdo) {
    $table = pl_find_table($pdo, ['channels', 'broadcast_channels', 'streams']);
    $info['table'] = $table;
    if ($table) {
        $cols = pl_columns($pdo, $table);
        $info['columns'] = $cols;
        $liveCol = pl_pick_col($cols, ['last_stream_live', 'is_live', 'live', 'online']);
        $viewCol = pl_pick_col($cols, ['views', 'viewers', 'viewer_count']);
        $publicCol = pl_pick_col($cols, ['is_public']);
        $statusCol = pl_pick_col($cols, ['status']);
        $typeCol = pl_pick_col($cols, ['type']);

        $where = ['1=1'];
        if ($publicCol) $where[] = "(`$publicCol` = 1 OR `$publicCol` = '1' OR `$publicCol` IS NULL)";
        // status: approved / active / published — не режем жёстко, только не rejected если есть
        if ($statusCol) {
            // Только опубликованные — иначе channel.php отдаёт 403 гостю
            $where[] = "(`$statusCol` = 'approved' OR `$statusCol` = 'active' OR `$statusCol` = 'published' OR `$statusCol` IS NULL)";
        }
        if ($type === 'tv' && $typeCol) $where[] = "LOWER(`$typeCol`) LIKE '%tv%'";
        if ($type === 'radio' && $typeCol) $where[] = "LOWER(`$typeCol`) LIKE '%radio%'";
        if (($type === 'channel' || $type === 'channels') && $typeCol) {
            $where[] = "(LOWER(`$typeCol`) NOT LIKE '%radio%')";
        }

        $orderParts = [];
        if ($liveCol) $orderParts[] = "`$liveCol` DESC";
        if ($viewCol) $orderParts[] = "`$viewCol` DESC";
        $order = $orderParts ? implode(', ', $orderParts) : 'id DESC';

        try {
            $sql = "SELECT * FROM `$table` WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT " . (int)$limit;
            $info['sql'] = $sql;
            $st = $pdo->query($sql);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $items[] = pl_normalize_channel($row, $cols, $site);
            }
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
            // fallback без where
            try {
                $st = $pdo->query("SELECT * FROM `$table` ORDER BY id DESC LIMIT " . (int)$limit);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $items[] = pl_normalize_channel($row, $cols, $site);
                }
            } catch (Throwable $e2) {
                $info['error2'] = $e2->getMessage();
            }
        }
    }
}

// клиентский фильтр type если в SQL не отфильтровали
if (in_array($type, ['tv', 'radio'], true) && $items) {
    $items = array_values(array_filter($items, function ($i) use ($type) {
        return ($i['type'] ?? '') === $type;
    }));
}

if ($debug) {
    pl_json(['items' => $items, 'info' => $info, 'count' => count($items)]);
}
pl_json(array_values($items));
