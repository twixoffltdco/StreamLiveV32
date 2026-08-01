<?php
/**
 * Что нового — форум + видео + ТВ/радио каналы (live при открытой вкладке).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = current_user();

$pageTitle = 'Что нового';
require_once __DIR__ . '/includes/header.php';

$hours = max(1, min(168, (int)($_GET['hours'] ?? 48)));
$tab = $_GET['tab'] ?? 'all';
if (!in_array($tab, ['all', 'forum', 'videos', 'channels'], true)) $tab = 'all';
$since = date('Y-m-d H:i:s', time() - $hours * 3600);
$items = [];

if ($tab === 'all' || $tab === 'forum') {
  try {
    $st = db()->prepare(
      "SELECT t.id, t.title, t.created_at, t.last_post_at, u.username, fc.title AS cat
       FROM forum_threads t
       JOIN users u ON u.id = t.user_id
       JOIN forum_categories fc ON fc.id = t.category_id
       WHERE t.is_deleted = 0 AND (t.created_at >= ? OR t.last_post_at >= ?)
       ORDER BY GREATEST(t.created_at, IFNULL(t.last_post_at, t.created_at)) DESC LIMIT 40"
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
  } catch (Throwable $e) {}
}

if ($tab === 'all' || $tab === 'videos') {
  try {
    $st = db()->prepare(
      "SELECT v.id, v.slug, v.title, v.created_at, v.views_count, u.username
       FROM videos v
       LEFT JOIN users u ON u.id = v.user_id
       WHERE v.created_at >= ? AND (v.is_deleted = 0 OR v.is_deleted IS NULL)
       ORDER BY v.created_at DESC LIMIT 40"
    );
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
      $items[] = [
        'type' => 'video',
        'ts' => strtotime($r['created_at']),
        'title' => $r['title'],
        'meta' => ($r['username'] ? '@'.$r['username'].' · ' : '') . (int)$r['views_count'] . ' просм.',
        'url' => !empty($r['slug']) ? '/watch.php?v=' . urlencode($r['slug']) : '/video.php?id=' . (int)$r['id'],
        'badge' => 'Видео',
      ];
    }
  } catch (Throwable $e) {
    try {
      $st = db()->prepare(
        "SELECT id, title, created_at FROM videos WHERE created_at >= ? ORDER BY created_at DESC LIMIT 40"
      );
      $st->execute([$since]);
      foreach ($st->fetchAll() as $r) {
        $items[] = [
          'type' => 'video', 'ts' => strtotime($r['created_at']),
          'title' => $r['title'], 'meta' => '', 'url' => '/video.php?id='.(int)$r['id'], 'badge' => 'Видео',
        ];
      }
    } catch (Throwable $e2) {}
  }
}

if ($tab === 'all' || $tab === 'channels') {
  try {
    $st = db()->prepare(
      "SELECT id, slug, title, type, created_at FROM channels
       WHERE created_at >= ? AND (is_deleted = 0 OR is_deleted IS NULL)
       ORDER BY created_at DESC LIMIT 40"
    );
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
      $kind = (($r['type'] ?? '') === 'radio') ? 'Радио' : 'ТВ';
      $items[] = [
        'type' => 'channel',
        'ts' => strtotime($r['created_at']),
        'title' => $r['title'],
        'meta' => $kind,
        'url' => '/channel-pc.php?slug=' . urlencode($r['slug']),
        'badge' => $kind,
      ];
    }
  } catch (Throwable $e) {}
}

usort($items, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
$items = array_slice($items, 0, 60);
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
            <option value="<?= $h ?>" <?= $hours===$h?'selected':'' ?>><?= $h < 24 ? ($h.' ч') : (($h/24).' дн') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </div>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <?php
    $tabs = ['all'=>'Всё','forum'=>'Форум','videos'=>'Видео','channels'=>'ТВ / Радио'];
    foreach ($tabs as $k=>$label):
      $cls = $tab === $k ? 'btn btn-primary btn-sm' : 'btn btn-outline btn-sm';
    ?>
      <a class="<?= $cls ?>" href="/forum_whats_new.php?tab=<?= $k ?>&hours=<?= $hours ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <p style="color:var(--text-dim);font-size:13px;margin-bottom:14px">Новые темы, видео и каналы. Список можно держать открытым.</p>

  <div class="forum-thread-list" id="whats-new-list">
    <?php foreach ($items as $it): ?>
      <div class="forum-thread-row">
        <div class="forum-thread-main">
          <span class="pin-badge" style="background:<?= $it['type']==='forum'?'#3b82f6':($it['type']==='video'?'#a855f7':'#22c55e') ?>"><?= e($it['badge']) ?></span>
          <a href="<?= e($it['url']) ?>" class="forum-thread-title"><?= e($it['title']) ?></a>
          <div style="color:var(--text-dim);font-size:12px;margin-top:2px">
            <?= e($it['meta']) ?>
            <?php if (!empty($it['ts'])): ?> · <?= e(date('d.m.Y H:i', $it['ts'])) ?><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <div class="empty-state"><h2>Пока тихо</h2><p>За период ничего нового.</p></div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
