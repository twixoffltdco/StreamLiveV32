<?php
/**
 * Блок «Для вас» в стиле Платформы.
 * Персонализация:
 *  - cookie viewed_channels / viewed_videos / vh (история браузера)
 *  - лайки / избранное, если пользователь вошёл
 *  - тип (tv/radio) из истории
 *  - добор популярными approved-каналами
 * Без 500: весь код в try/catch.
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

    $chTable = pl_find_table($pdo, ['channels']);
    if (!$chTable) {
        return;
    }
    $cols = pl_columns($pdo, $chTable);
    $idCol = pl_pick_col($cols, ['id']);
    $statusCol = pl_pick_col($cols, ['status']);
    $publicCol = pl_pick_col($cols, ['is_public']);
    $viewCol = pl_pick_col($cols, ['views', 'viewers']);
    $liveCol = pl_pick_col($cols, ['last_stream_live', 'is_live']);
    $typeCol = pl_pick_col($cols, ['type']);
    $slugCol = pl_pick_col($cols, ['slug']);

    // ---- история из cookie (браузер) ----
    $cookieIds = static function (string $name): array {
        if (empty($_COOKIE[$name])) {
            return [];
        }
        $raw = (string)$_COOKIE[$name];
        $parts = preg_split('/[,\s]+/', $raw) ?: [];
        $ids = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $ids[] = $n;
            }
        }
        return array_values(array_unique($ids));
    };

    $viewedChannels = $cookieIds('viewed_channels');
    $viewedVideos = $cookieIds('viewed_videos');
    $vh = $cookieIds('vh'); // video history из includes/recommendations.php
    $historyChannelIds = $viewedChannels;

    // Типы из истории просмотров каналов
    $preferredTypes = [];
    if ($historyChannelIds && $idCol && $typeCol) {
        try {
            $ph = implode(',', array_fill(0, count($historyChannelIds), '?'));
            $st = $pdo->prepare("SELECT `$typeCol` AS t, COUNT(*) AS c FROM `$chTable` WHERE `$idCol` IN ($ph) GROUP BY `$typeCol`");
            $st->execute($historyChannelIds);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $t = strtolower(trim((string)($row['t'] ?? '')));
                if ($t !== '') {
                    $preferredTypes[$t] = (int)$row['c'];
                }
            }
            arsort($preferredTypes);
        } catch (Throwable $e) { /* ignore */ }
    }

    $baseWhere = ['1=1'];
    if ($statusCol) {
        $baseWhere[] = "(`$statusCol` = 'approved' OR `$statusCol` = 'active' OR `$statusCol` = 'published')";
    }
    if ($publicCol) {
        $baseWhere[] = "(`$publicCol` = 1 OR `$publicCol` = '1' OR `$publicCol` IS NULL)";
    }
    $whereSql = implode(' AND ', $baseWhere);

    $items = [];
    $seen = [];

    $pushRow = static function (array $row) use (&$items, &$seen, $cols, $site, $idCol): void {
        $n = pl_normalize_channel($row, $cols, $site);
        $key = (string)($n['id'] ?? '');
        if ($key === '' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        // Только рабочие query-URL (без /channel/slug → 404 при кривом rewrite)
        if (!empty($n['slug'])) {
            $n['href'] = '/channel.php?slug=' . rawurlencode((string)$n['slug']);
        } elseif ($key !== '') {
            $n['href'] = '/channel.php?id=' . rawurlencode($key);
        } elseif (!empty($n['embed']) && is_string($n['embed'])) {
            // embed может быть абсолютным — вытащим path
            $emb = (string)$n['embed'];
            if (preg_match('#/channel\.php\?[^\s"\']+#', $emb, $mm)) {
                $n['href'] = $mm[0];
            } elseif (preg_match('#https?://[^/]+(/.*)$#', $emb, $mm)) {
                $n['href'] = $mm[1];
            } else {
                $n['href'] = '/catalog.php';
            }
        } else {
            $n['href'] = '/catalog.php';
        }
        $items[] = $n;
    };

    // 1) Лайкнутые / избранные каналы пользователя
    if ($user_id > 0 && $idCol) {
        foreach (['channel_likes', 'favorites', 'channel_favorites'] as $likeName) {
            $likeTable = pl_find_table($pdo, [$likeName]);
            if (!$likeTable) {
                continue;
            }
            try {
                $lcols = pl_columns($pdo, $likeTable);
                $uidCol = pl_pick_col($lcols, ['user_id', 'uid']);
                $cidCol = pl_pick_col($lcols, ['channel_id', 'cid', 'item_id']);
                if (!$uidCol || !$cidCol) {
                    continue;
                }
                $st = $pdo->prepare(
                    "SELECT c.* FROM `$chTable` c
                     INNER JOIN `$likeTable` l ON l.`$cidCol` = c.`$idCol`
                     WHERE l.`$uidCol` = ? AND $whereSql
                     LIMIT 12"
                );
                $st->execute([$user_id]);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $pushRow($row);
                }
            } catch (Throwable $e) { /* ignore */ }
            if (count($items) >= 8) {
                break;
            }
        }
    }

    // 2) Каналы из cookie-истории (недавно смотрел)
    if ($historyChannelIds && $idCol && count($items) < 16) {
        try {
            $ph = implode(',', array_fill(0, count($historyChannelIds), '?'));
            $st = $pdo->prepare("SELECT * FROM `$chTable` WHERE `$idCol` IN ($ph) AND $whereSql LIMIT 16");
            $st->execute($historyChannelIds);
            $byId = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $byId[(int)$row[$idCol]] = $row;
            }
            // сохраняем порядок истории (свежие первые)
            foreach ($historyChannelIds as $hid) {
                if (isset($byId[$hid])) {
                    $pushRow($byId[$hid]);
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    // 3) Похожие по типу (tv/radio) из предпочтений
    if ($preferredTypes && $typeCol && $idCol && count($items) < 16) {
        $topType = array_key_first($preferredTypes);
        if ($topType) {
            try {
                $exclude = array_keys($seen);
                $sql = "SELECT * FROM `$chTable` WHERE $whereSql AND LOWER(`$typeCol`) LIKE ?";
                $params = ['%' . $topType . '%'];
                if ($exclude) {
                    $ph = implode(',', array_fill(0, count($exclude), '?'));
                    $sql .= " AND `$idCol` NOT IN ($ph)";
                    foreach ($exclude as $ex) {
                        $params[] = $ex;
                    }
                }
                $ord = [];
                if ($liveCol) {
                    $ord[] = "`$liveCol` DESC";
                }
                if ($viewCol) {
                    $ord[] = "`$viewCol` DESC";
                }
                $sql .= ' ORDER BY ' . ($ord ? implode(',', $ord) : "`$idCol` DESC") . ' LIMIT 12';
                $st = $pdo->prepare($sql);
                $st->execute($params);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $pushRow($row);
                    if (count($items) >= 16) {
                        break;
                    }
                }
            } catch (Throwable $e) { /* ignore */ }
        }
    }

    // 4) Добор: live + популярные
    if (count($items) < 12 && $idCol) {
        try {
            $exclude = array_keys($seen);
            $sql = "SELECT * FROM `$chTable` WHERE $whereSql";
            $params = [];
            if ($exclude) {
                $ph = implode(',', array_fill(0, count($exclude), '?'));
                $sql .= " AND `$idCol` NOT IN ($ph)";
                $params = $exclude;
            }
            $ord = [];
            if ($liveCol) {
                $ord[] = "`$liveCol` DESC";
            }
            if ($viewCol) {
                $ord[] = "`$viewCol` DESC";
            }
            $sql .= ' ORDER BY ' . ($ord ? implode(',', $ord) : "`$idCol` DESC") . ' LIMIT 24';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $pushRow($row);
                if (count($items) >= 16) {
                    break;
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    if (!$items) {
        return;
    }

    $items = array_slice($items, 0, 16);
    $personalized = ($user_id > 0) || !empty($historyChannelIds) || !empty($preferredTypes);
    $heading = $personalized ? 'Для вас' : 'Рекомендуем';
    ?>
<section class="pl-rec-block" style="margin:20px 0;padding:0 8px">
  <h2 style="font-size:18px;font-weight:600;margin:0 0 12px;color:inherit"><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">
    <?php foreach ($items as $it):
      $href = (string)($it['href'] ?? '');
      if ($href === '' && !empty($it['slug'])) {
          $href = '/channel.php?slug=' . rawurlencode((string)$it['slug']);
      } elseif ($href === '' && !empty($it['id'])) {
          $href = '/channel.php?id=' . rawurlencode((string)$it['id']);
      } elseif ($href === '') {
          $href = '/catalog.php';
      }
      // абсолютный URL с чужим path → оставить path+query
      if (preg_match('#^https?://#i', $href)) {
          $parts = parse_url($href);
          $href = ($parts['path'] ?? '/catalog.php') . (isset($parts['query']) ? '?' . $parts['query'] : '');
      }
      // устаревший /channel/slug → channel.php?slug=
      if (preg_match('#^/channel/([^/?#]+)/?$#', $href, $mm)) {
          $href = '/channel.php?slug=' . rawurlencode(rawurldecode($mm[1]));
      }
      $title = $it['title'] ?? '';
      $meta = $it['meta'] ?? '';
      $thumb = $it['thumb'] ?? '';
      $live = !empty($it['live']);
    ?>
    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" style="text-decoration:none;color:inherit;display:block">
      <div style="aspect-ratio:16/9;background:#212121;border-radius:12px;overflow:hidden;position:relative">
        <?php if ($thumb !== ''): ?>
          <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover">
        <?php endif; ?>
        <?php if ($live): ?>
          <span style="position:absolute;bottom:6px;left:6px;background:#f00;color:#fff;font-size:11px;padding:2px 6px;border-radius:4px">В ЭФИРЕ</span>
        <?php endif; ?>
      </div>
      <div style="padding:8px 2px 0;font-size:14px;font-weight:500"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-size:12px;opacity:.7;padding:2px"><?= htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') ?></div>
    </a>
    <?php endforeach; ?>
  </div>
</section>
    <?php
} catch (Throwable $e) {
    return;
}
