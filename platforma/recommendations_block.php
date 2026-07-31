<?php
/**
 * Рекомендации — полностью в try/catch, без 500.
 */
try {
    if (defined('PL_REC_BLOCK_RENDERED')) {
        return;
    }
    define('PL_REC_BLOCK_RENDERED', true);

    if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $boot = __DIR__ . '/api_bootstrap.php';
    if (!is_file($boot)) {
        return;
    }
    require_once $boot;

    $pdo = function_exists('pl_pdo') ? pl_pdo() : null;
    if (!$pdo) {
        return;
    }
    $site = function_exists('pl_site_web') ? pl_site_web() : '';
    $user_id = 0;
    if (!empty($_SESSION['user_id'])) {
        $user_id = (int)$_SESSION['user_id'];
    } elseif (!empty($_SESSION['user']['id'])) {
        $user_id = (int)$_SESSION['user']['id'];
    }

    $items = [];
    $chTable = pl_find_table($pdo, ['channels']);
    if (!$chTable) {
        return;
    }
    $cols = pl_columns($pdo, $chTable);
    $viewCol = pl_pick_col($cols, ['views', 'viewers']);
    $liveCol = pl_pick_col($cols, ['last_stream_live', 'is_live']);
    $publicCol = pl_pick_col($cols, ['is_public']);
    $ord = [];
    if ($liveCol) $ord[] = "`$liveCol` DESC";
    if ($viewCol) $ord[] = "`$viewCol` DESC";
    $order = $ord ? implode(',', $ord) : 'id DESC';
    $where = '1=1';
    if ($publicCol) {
        $where .= " AND (`$publicCol`=1 OR `$publicCol`='1' OR `$publicCol` IS NULL)";
    }
    $st = $pdo->query("SELECT * FROM `$chTable` WHERE $where ORDER BY $order LIMIT 12");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $items[] = pl_normalize_channel($row, $cols, $site);
    }
    if (!$items) {
        return;
    }
    ?>
<section class="pl-rec-block" style="margin:20px 0;padding:0 8px">
  <h2 style="font-size:18px;font-weight:600;margin:0 0 12px;color:inherit">Персональные рекомендации</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">
    <?php foreach ($items as $it):
      $id = isset($it['id']) ? $it['id'] : '';
      $href = '/channel.php?id=' . rawurlencode((string)$id);
      $title = isset($it['title']) ? $it['title'] : '';
      $meta = isset($it['meta']) ? $it['meta'] : '';
      $thumb = isset($it['thumb']) ? $it['thumb'] : '';
      $live = !empty($it['live']);
    ?>
    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" style="text-decoration:none;color:inherit;display:block">
      <div style="aspect-ratio:16/9;background:#212121;border-radius:12px;overflow:hidden;position:relative">
        <?php if ($thumb !== ''): ?><img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover"><?php endif; ?>
        <?php if ($live): ?><span style="position:absolute;bottom:6px;left:6px;background:#f00;color:#fff;font-size:11px;padding:2px 6px;border-radius:4px">В ЭФИРЕ</span><?php endif; ?>
      </div>
      <div style="padding:8px 2px 0;font-size:14px;font-weight:500"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-size:12px;opacity:.7;padding:2px"><?= htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
    <?php
} catch (Throwable $e) {
    // молча — сайт не падает
    return;
}
