<?php
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';

$limit = min(40, max(1, (int)($_GET['limit'] ?? 30)));
$pdo = pl_pdo();
$site = pl_site_web();
$items = [];

if ($pdo) {
    $table = pl_find_table($pdo, ['resources', 'resource', 'files', 'downloads', 'docs']);
    if ($table) {
        $cols = pl_columns($pdo, $table);
        $idCol = pl_pick_col($cols, ['id']);
        $titleCol = pl_pick_col($cols, ['title', 'name', 'filename', 'label']);
        $thumbCol = pl_pick_col($cols, ['thumbnail', 'thumb', 'image', 'icon', 'cover']);
        try {
            $st = $pdo->query("SELECT * FROM `$table` ORDER BY " . ($idCol ? "`$idCol` DESC" : '1') . " LIMIT " . (int)$limit);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = $idCol ? ($row[$idCol] ?? '') : '';
                $title = $titleCol ? (string)($row[$titleCol] ?? 'Ресурс') : 'Ресурс';
                $thumb = $thumbCol ? (string)($row[$thumbCol] ?? '') : '';
                $items[] = [
                    'id' => $id,
                    'title' => $title,
                    'type' => 'resource',
                    'meta' => 'Ресурс',
                    'live' => false,
                    'thumb' => $thumb,
                    'embed' => $site . '/resource.php?id=' . rawurlencode((string)$id),
                ];
            }
        } catch (Throwable $e) {}
    }
}
pl_json(array_values($items));
