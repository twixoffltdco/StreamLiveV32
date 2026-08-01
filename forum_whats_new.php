<?php
/**
 * Что нового — форум + видео + ТВ/радио (без заглушек, устойчивые запросы).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = current_user();

$pageTitle = 'Что нового';
require_once __DIR__ . '/includes/header.php';

$hours = max(1, min(168, (int)($_GET['hours'] ?? 48)));
$tab = $_GET['tab'] ?? 'all';
if (!in_array($tab, ['all', 'forum', 'videos', 'channels', 'tv', 'radio'], true)) $tab = 'all';
$since = date('Y-m-d H:i:s', time() - $hours * 3600);
$items = [];

// ---- Форум ----
if ($tab === 'all' || $tab === 'forum') {
  try {
    $st = db()->prepare(
      "SELECT t.id, t.title, t.created_at, t.last_post_at, u.username, fc.title AS cat
       FROM forum_threads t
       JOIN users u ON u.id = t.user_id
       JOIN forum_categories fc ON fc.id = t.category_id
       WHERE t.is_deleted = 0 AND (t.created_at >= ? OR t.last_post_at >= ?)
       ORDER BY GREATEST(t.created_at, IFNULL(t.last_post_at, t.created_at)) DESC
       LIMIT 40"
    );
    $st->execute([$since, $since]);
    foreach ($st->fetchAll() as $r) {
      $ts = max(strtotime($r['created_at']), strtotime($r['last_post_at'] ?? $r['created_at']));
      $items[] = [
        'type' => 'forum',
        'ts' => $ts,
        'title' => $r['title'],
        'meta' => '@' . $r['username'] . ' · ' . $r['cat'],
        'url' => '/forum_thread.php?id=' . (int)$r['id'],
        'badge' => 'Форум',
      ];
    }
  } catch (Throwable $e) {
    try {
      $st = db()->prepare(
        "SELECT t.id, t.title, t.created_at, u.username
         FROM forum_threads t LEFT JOIN users u ON u.id = t.user_id
         WHERE t.created_at >= ? ORDER BY t.created_at DESC LIMIT 40"
      );
      $st->execute([$since]);
      foreach ($st->fetchAll() as $r) {
        $items[] = [
          'type' => 'forum', 'ts' => strtotime($r['created_at']),
          'title' => $r['title'],
          'meta' => '@' . ($r['username'] ?? ''),
          'url' => '/forum_thread.php?id=' . (int)$r['id'],
          'badge' => 'Форум',
        ];
      }
    } catch (Throwable $e2) {}
  }
}

// ---- Видео (status=published, без is_deleted) ----
if ($tab === 'all' || $tab === 'videos') {
  $videoOk = false;
  try {
    $st = db()->prepare(
      "SELECT v.id, v.slug, v.title, v.created_at, v.views_count, u.username
       FROM videos v
       LEFT JOIN users u ON u.id = v.user_id
       WHERE v.status = 'published' AND v.created_at >= ?
       ORDER BY v.created_at DESC LIMIT 40"
    );
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
      $slug = (string)($r['slug'] ?? '');
      $items[] = [
        'type' => 'video',
        'ts' => strtotime($r['created_at']),
        'title' => $r['title'],
        'meta' => ($r['username'] ? '@' . $r['username'] . ' · ' : '') . (int)($r['views_count'] ?? 0) . ' просм.',
        'url' => $slug !== '' ? '/video/' . rawurlencode($slug) : '/video.php?id=' . (int)$r['id'],
        'badge' => 'Видео',
      ];
    }
    $videoOk = true;
  } catch (Throwable $e) {}

  if (!$videoOk) {
    try {
      $st = db()->prepare(
        "SELECT id, slug, title, created_at FROM videos
         WHERE created_at >= ? ORDER BY created_at DESC LIMIT 40"
      );
      $st->execute([$since]);
      foreach ($st->fetchAll() as $r) {
        $slug = (string)($r['slug'] ?? '');
        $items[] = [
          'type' => 'video',
          'ts' => strtotime($r['created_at']),
          'title' => $r['title'],
          'meta' => '',
          'url' => $slug !== '' ? '/video.php?slug=' . rawurlencode($slug) : '/video.php?id=' . (int)$r['id'],
          'badge' => 'Видео',
        ];
      }
    } catch (Throwable $e2) {}
  }
}

// ---- ТВ / Радио (без is_deleted — его нет в схеме) ----
$wantChannels = in_array($tab, ['all', 'channels', 'tv', 'radio'], true);
if ($wantChannels) {
  $typeFilter = null;
  if ($tab === 'tv') $typeFilter = 'tv';
  if ($tab === 'radio') $typeFilter = 'radio';

  $loaded = false;
  // Вариант 1: approved + created_at
  try {
    $sql = "SELECT id, slug, title, type, views, created_at, status
            FROM channels
            WHERE status IN ('approved','active','published') AND created_at >= ?";
    $params = [$since];
    if ($typeFilter) {
      $sql .= " AND type = ?";
      $params[] = $typeFilter;
    }
    $sql .= " ORDER BY created_at DESC LIMIT 40";
    $st = db()->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
      $kind = (strtolower((string)($r['type'] ?? '')) === 'radio') ? 'Радио' : 'ТВ';
      $slug = (string)($r['slug'] ?? '');
      $items[] = [
        'type' => 'channel',
        'ts' => strtotime($r['created_at'] ?? 'now'),
        'title' => $r['title'],
        'meta' => $kind . ' · ' . (int)($r['views'] ?? 0) . ' просм.',
        'url' => $slug !== '' ? '/channel.php?slug=' . rawurlencode($slug) : '/channel.php?id=' . (int)$r['id'],
        'badge' => $kind,
      ];
    }
    $loaded = true;
  } catch (Throwable $e) {}

  // Вариант 2: без status / без фильтра по дате строго
  if (!$loaded) {
    try {
      $sql = "SELECT id, slug, title, type, views, created_at FROM channels WHERE 1=1";
      $params = [];
      if ($typeFilter) {
        $sql .= " AND type = ?";
        $params[] = $typeFilter;
      }
      $sql .= " ORDER BY id DESC LIMIT 40";
      $st = db()->prepare($sql);
      $st->execute($params);
      foreach ($st->fetchAll() as $r) {
        // если есть created_at — фильтруем в PHP
        $ts = !empty($r['created_at']) ? strtotime($r['created_at']) : time();
        if ($ts < time() - $hours * 3600 && $tab !== 'tv' && $tab !== 'radio') {
          // для вкладки all/channels с датой — пропускаем старые
          if (in_array($tab, ['all', 'channels'], true)) continue;
        }
        $kind = (strtolower((string)($r['type'] ?? '')) === 'radio') ? 'Радио' : 'ТВ';
        $slug = (string)($r['slug'] ?? '');
        $items[] = [
          'type' => 'channel',
          'ts' => $ts,
          'title' => $r['title'],
          'meta' => $kind . ' · ' . (int)($r['views'] ?? 0) . ' просм.',
          'url' => $slug !== '' ? '/channel.php?slug=' . rawurlencode($slug) : '/channel.php?id=' . (int)$r['id'],
          'badge' => $kind,
        ];
      }
    } catch (Throwable $e2) {}
  }

  // Вариант 3: для вкладок tv/radio — всегда показать актуальный каталог, даже если «за период» пусто
  if (in_array($tab, ['tv', 'radio'], true)) {
    $hasType = false;
    foreach ($items as $it) {
      if ($it['type'] === 'channel') { $hasType = true; break; }
    }
    if (!$hasType) {
      try {
        $st = db()->prepare(
          "SELECT id, slug, title, type, views, created_at FROM channels
           WHERE type = ? AND status = 'approved'
           ORDER BY views DESC, id DESC LIMIT 30"
        );
        $st->execute([$typeFilter]);
        foreach ($st->fetchAll() as $r) {
          $kind = $typeFilter === 'radio' ? 'Радио' : 'ТВ';
          $slug = (string)($r['slug'] ?? '');
          $items[] = [
            'type' => 'channel',
            'ts' => strtotime($r['created_at'] ?? 'now'),
            'title' => $r['title'],
            'meta' => $kind . ' · ' . (int)($r['views'] ?? 0) . ' просм.',
            'url' => $slug !== '' ? '/channel.php?slug=' . rawurlencode($slug) : '/channel.php?id=' . (int)$r['id'],
            'badge' => $kind,
          ];
        }
      } catch (Throwable $e3) {
        try {
          $st = db()->prepare("SELECT id, slug, title, type, views FROM channels WHERE type = ? ORDER BY id DESC LIMIT 30");
          $st->execute([$typeFilter]);
          foreach ($st->fetchAll() as $r) {
            $kind = $typeFilter === 'radio' ? 'Радио' : 'ТВ';
            $slug = (string)($r['slug'] ?? '');
            $items[] = [
              'type' => 'channel', 'ts' => time(),
              'title' => $r['title'],
              'meta' => $kind,
              'url' => $slug !== '' ? '/channel.php?slug=' . rawurlencode($slug) : '/channel.php?id=' . (int)$r['id'],
              'badge' => $kind,
            ];
          }
        } catch (Throwable $e4) {}
      }
    }
  }
}

usort($items, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
$items = array_slice($items, 0, 80);

$countForum = count(array_filter($items, fn($i) => $i['type'] === 'forum'));
$countVideo = count(array_filter($items, fn($i) => $i['type'] === 'video'));
$countCh = count(array_filter($items, fn($i) => $i['type'] === 'channel'));
?>
<div class="container">
  <p style="margin:20px 0 4px"><a href="/forum.php" style="color:var(--accent-2);font-size:13px">← Форум</a></p>

  <div class="forum-tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 16px">
    <a href="/forum.php" class="btn btn-outline btn-sm">Категории</a>
    <a href="/forum_whats_new.php" class="btn btn-primary btn-sm">Что нового</a>
  </div>

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <h1 style="margin:0">Что нового</h1>
    <form method="get" style="display:flex;gap:8px;align-items:center;font-size:13px;flex-wrap:wrap">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <label style="color:var(--text-dim)">за
        <select name="hours" onchange="this.form.submit()" style="margin-left:6px;padding:6px 8px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:inherit">
          <?php foreach ([6,12,24,48,72,168] as $h): ?>
            <option value="<?= $h ?>" <?= $hours === $h ? 'selected' : '' ?>><?= $h < 24 ? ($h . ' ч') : (($h / 24) . ' дн') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </div>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <?php
    $tabs = [
      'all' => 'Всё',
      'forum' => 'Форум' . ($countForum ? " ($countForum)" : ''),
      'videos' => 'Видео' . ($countVideo ? " ($countVideo)" : ''),
      'channels' => 'ТВ / Радио' . ($countCh ? " ($countCh)" : ''),
      'tv' => 'Только ТВ',
      'radio' => 'Только радио',
    ];
    foreach ($tabs as $k => $label):
      $cls = $tab === $k ? 'btn btn-primary btn-sm' : 'btn btn-outline btn-sm';
    ?>
      <a class="<?= $cls ?>" href="/forum_whats_new.php?tab=<?= e($k) ?>&hours=<?= (int)$hours ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <p style="color:var(--text-dim);font-size:13px;margin-bottom:14px">
    Новые темы, опубликованные видео и каналы (ТВ / радио).
    <?php if ($tab === 'tv' || $tab === 'radio'): ?>Показан актуальный каталог<?= $tab === 'tv' ? ' телеканалов' : ' радио' ?>.<?php endif; ?>
  </p>

  <div class="forum-thread-list" id="whats-new-list">
    <?php foreach ($items as $it): ?>
      <div class="forum-thread-row">
        <div class="forum-thread-main">
          <span class="pin-badge" style="background:<?= $it['type'] === 'forum' ? '#3b82f6' : ($it['type'] === 'video' ? '#a855f7' : '#22c55e') ?>"><?= e($it['badge']) ?></span>
          <a href="<?= e($it['url']) ?>" class="forum-thread-title"><?= e($it['title']) ?></a>
          <div style="color:var(--text-dim);font-size:12px;margin-top:2px">
            <?= e($it['meta']) ?>
            <?php if (!empty($it['ts'])): ?> · <?= e(date('d.m.Y H:i', (int)$it['ts'])) ?><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <div class="empty-state">
        <h2>Пока тихо</h2>
        <p>За выбранный период ничего не найдено.
          <?php if ($tab === 'videos'): ?>Попробуйте вкладку «Только ТВ» / «Только радио» или увеличьте период.<?php endif; ?>
        </p>
        <p style="margin-top:10px">
          <a class="btn btn-outline btn-sm" href="/catalog.php?type=tv">Каталог ТВ</a>
          <a class="btn btn-outline btn-sm" href="/catalog.php?type=radio">Каталог радио</a>
          <a class="btn btn-outline btn-sm" href="/videos">Все видео</a>
        </p>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
