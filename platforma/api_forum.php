<?php
declare(strict_types=1);
require_once __DIR__ . '/api_bootstrap.php';

$limit = min(40, max(1, (int)($_GET['limit'] ?? 30)));
$pdo = pl_pdo();
$site = pl_site_web();
$items = [];

if ($pdo) {
    $tTable = pl_find_table($pdo, ['forum_threads', 'threads']);
    $cTable = pl_find_table($pdo, ['forum_categories', 'categories']);
    if ($tTable) {
        $cols = pl_columns($pdo, $tTable);
        $idCol = pl_pick_col($cols, ['id', 'thread_id']);
        $titleCol = pl_pick_col($cols, ['title', 'subject', 'name']);
        $catCol = pl_pick_col($cols, ['category_id', 'cat_id', 'forum_id']);
        $viewsCol = pl_pick_col($cols, ['views', 'view_count']);
        $repliesCol = pl_pick_col($cols, ['replies', 'reply_count', 'posts_count', 'post_count']);
        $updatedCol = pl_pick_col($cols, ['updated_at', 'last_post_at', 'bumped_at', 'created_at']);
        $order = $updatedCol ? "`$updatedCol` DESC" : ($idCol ? "`$idCol` DESC" : '1');
        try {
            $st = $pdo->query("SELECT * FROM `$tTable` ORDER BY $order LIMIT " . (int)$limit);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = $idCol ? ($row[$idCol] ?? '') : '';
                $title = $titleCol ? (string)($row[$titleCol] ?? 'Тема') : 'Тема';
                $views = $viewsCol ? (int)($row[$viewsCol] ?? 0) : 0;
                $replies = $repliesCol ? (int)($row[$repliesCol] ?? 0) : 0;
                $meta = 'Форум';
                if ($replies) $meta .= ' · ' . $replies . ' ответов';
                if ($views) $meta .= ' · ' . number_format($views) . ' просм.';
                $items[] = [
                    'id' => $id,
                    'title' => $title,
                    'type' => 'forum',
                    'meta' => $meta,
                    'live' => false,
                    'thumb' => '',
                    // типичные URL StreamLife
                    'embed' => $site . '/forum_thread.php?id=' . rawurlencode((string)$id),
                    'url' => $site . '/forum_thread.php?id=' . rawurlencode((string)$id),
                ];
            }
        } catch (Throwable $e) {}
    }
}
pl_json(array_values($items));
