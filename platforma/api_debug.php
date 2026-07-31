<?php
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';
$pdo = pl_pdo();
$out = [
    'pdo' => $pdo ? 'connected' : 'NOT connected',
    'site' => pl_site_web(),
    'roots' => pl_root_candidates(),
    'tables' => [],
    'channel_table' => null,
    'sample' => [],
];
if ($pdo) {
    $out['tables'] = pl_tables($pdo);
    $out['channel_table'] = pl_find_table($pdo, ['channels', 'broadcast_channels', 'streams']);
    if ($out['channel_table']) {
        $cols = pl_columns($pdo, $out['channel_table']);
        $out['columns'] = $cols;
        try {
            $st = $pdo->query("SELECT * FROM `{$out['channel_table']}` LIMIT 3");
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $out['sample'][] = pl_normalize_channel($row, $cols, pl_site_web());
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
    }
}
pl_json($out);
