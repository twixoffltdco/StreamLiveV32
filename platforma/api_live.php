<?php
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';

$limit = min(30, max(1, (int)($_GET['limit'] ?? 12)));
$pdo = pl_pdo();
$site = pl_site_web();
$items = [];

if ($pdo) {
    $table = pl_find_table($pdo, ['channels', 'broadcast_channels', 'streams']);
    if ($table) {
        $cols = pl_columns($pdo, $table);
        $liveCol = pl_pick_col($cols, ['last_stream_live', 'is_live', 'live', 'online']);
        $viewCol = pl_pick_col($cols, ['views', 'viewers', 'viewer_count']);
        $publicCol = pl_pick_col($cols, ['is_public']);
        try {
            if ($liveCol) {
                $where = "`$liveCol` IN (1,'1',true)";
                if ($publicCol) $where .= " AND (`$publicCol` = 1 OR `$publicCol` = '1' OR `$publicCol` IS NULL)";
                $order = $viewCol ? "`$viewCol` DESC" : 'id DESC';
                $st = $pdo->query("SELECT * FROM `$table` WHERE $where ORDER BY $order LIMIT " . (int)$limit);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $items[] = pl_normalize_channel($row, $cols, $site);
                }
            }
        } catch (Throwable $e) {}
    }
}
pl_json(array_values($items));
