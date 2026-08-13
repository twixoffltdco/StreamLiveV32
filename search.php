<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = current_user();

$q = trim((string)($_GET['q'] ?? ''));
$pageTitle = $q !== '' ? 'Поиск: ' . $q : 'Поиск по сайту';
require_once __DIR__ . '/includes/header.php';

$results = ['videos' => [], 'channels' => [], 'broadcast_channels' => [], 'threads' => [], 'users' => []];

if ($q !== '') {
  // Видео — FULLTEXT уже был (019_videos.sql)
  try {
    $stmt = db()->prepare(
      "SELECT v.slug, v.title, v.thumbnail_url, c.title AS channel_title FROM videos v
       JOIN channels c ON c.id = v.channel_id
       WHERE v.status = 'published' AND MATCH(v.title, v.description, v.tags) AGAINST (? IN NATURAL LANGUAGE MODE)
       LIMIT 8"
    );
    $stmt->execute([$q]);
    $results['videos'] = $stmt->fetchAll();
  } catch (\Throwable $e) { /* если FULLTEXT ещё не применён — просто не показываем этот блок */ }

  // ТВ/радио каналы
  try {
    $stmt = db()->prepare(
      "SELECT slug, title, description, logo_url, type FROM channels
       WHERE status = 'approved' AND MATCH(title, description) AGAINST (? IN NATURAL LANGUAGE MODE)
       LIMIT 8"
    );
    $stmt->execute([$q]);
    $results['channels'] = $stmt->fetchAll();
  } catch (\Throwable $e) { }

  // Каналы-рассылки в мессенджере
  try {
    $stmt = db()->prepare(
      "SELECT slug, title, description, avatar_url FROM broadcast_channels
       WHERE MATCH(title, description) AGAINST (? IN NATURAL LANGUAGE MODE) LIMIT 8"
    );
    $stmt->execute([$q]);
    $results['broadcast_channels'] = $stmt->fetchAll();
  } catch (\Throwable $e) { }

  // Темы форума
  try {
    $stmt = db()->prepare(
      "SELECT t.id, t.title, t.created_at, u.username FROM forum_threads t
       JOIN users u ON u.id = t.user_id
       WHERE t.is_deleted = 0 AND MATCH(t.title) AGAINST (? IN NATURAL LANGUAGE MODE) LIMIT 8"
    );
    $stmt->execute([$q]);
    $results['threads'] = $stmt->fetchAll();
  } catch (\Throwable $e) { }

  // Пользователи — короткие строки, FULLTEXT тут не поможет, обычный LIKE
  try {
    $stmt = db()->prepare("SELECT username, avatar, gravatar_email FROM users WHERE username LIKE ? AND is_banned = 0 LIMIT 8");
    $stmt->execute(['%' . $q . '%']);
    $results['users'] = $stmt->fetchAll();
  } catch (\Throwable $e) { }
}

$totalFound = array_sum(array_map('count', $results));
?>
<div class="container">
  <h1 style="margin:24px 0 16px">🔎 Поиск по <?= e(SITE_NAME) ?></h1>
  <form method="GET" style="display:flex;gap:8px;max-width:520px;margin-bottom:24px">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Видео, каналы, темы форума, пользователи…" autofocus style="flex:1;padding:11px">
    <button class="btn btn-primary" type="submit">Найти</button>
  </form>

  <?php if ($q === ''): ?>
    <p style="color:var(--text-dim)">Введите запрос — ищем сразу по видео, ТВ/радио каналам, каналам в мессенджере, темам форума и пользователям.</p>
  <?php elseif ($totalFound === 0): ?>
    <div class="empty-state"><p>По запросу «<?= e($q) ?>» ничего не нашлось.</p></div>
  <?php endif; ?>

  <?php if ($results['videos']): ?>
    <h2 style="font-size:16px;margin:20px 0 10px">📺 Видео</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:20px">
      <?php foreach ($results['videos'] as $v): ?>
        <a href="/video?slug=<?= e($v['slug']) ?>" style="text-decoration:none;color:inherit">
          <div style="aspect-ratio:16/9;background:#111 url('<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>') center/cover;border-radius:10px"></div>
          <div style="padding:6px 2px"><b style="display:block;font-size:13px"><?= e(mb_substr($v['title'], 0, 60)) ?></b>
          <span style="font-size:12px;color:var(--text-dim)"><?= e($v['channel_title']) ?></span></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($results['channels']): ?>
    <h2 style="font-size:16px;margin:20px 0 10px">📡 ТВ / радио каналы</h2>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px">
      <?php foreach ($results['channels'] as $c): ?>
        <a href="/channel?slug=<?= e($c['slug']) ?>" class="form-card" style="display:flex;align-items:center;gap:10px;padding:10px 14px;text-decoration:none;color:inherit">
          <img src="<?= e($c['logo_url'] ?: '/assets/img/avatar-placeholder.png') ?>" alt="" style="width:36px;height:36px;border-radius:8px;object-fit:cover" onerror="this.style.display='none'">
          <div><b style="font-size:13px"><?= e($c['title']) ?></b><div style="font-size:11px;color:var(--text-dim)"><?= $c['type'] === 'radio' ? 'Радио' : 'ТВ' ?></div></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($results['broadcast_channels']): ?>
    <h2 style="font-size:16px;margin:20px 0 10px">💬 Каналы в мессенджере</h2>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px">
      <?php foreach ($results['broadcast_channels'] as $bc): ?>
        <a href="/broadcast_channel?slug=<?= e($bc['slug']) ?>" class="form-card" style="display:flex;align-items:center;gap:10px;padding:10px 14px;text-decoration:none;color:inherit">
          <img src="<?= e($bc['avatar_url'] ?: '/assets/img/avatar-placeholder.png') ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover" onerror="this.style.display='none'">
          <b style="font-size:13px"><?= e($bc['title']) ?></b>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($results['threads']): ?>
    <h2 style="font-size:16px;margin:20px 0 10px">💭 Темы форума</h2>
    <div style="margin-bottom:20px">
      <?php foreach ($results['threads'] as $t): ?>
        <div class="form-card" style="padding:10px 14px;margin-bottom:8px">
          <a href="/forum_thread?id=<?= (int)$t['id'] ?>" style="color:var(--accent-2);font-weight:600;font-size:13.5px"><?= e($t['title']) ?></a>
          <div style="font-size:11.5px;color:var(--text-dim);margin-top:2px">от <?= e($t['username']) ?> · <?= e($t['created_at']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($results['users']): ?>
    <h2 style="font-size:16px;margin:20px 0 10px">👤 Пользователи</h2>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px">
      <?php foreach ($results['users'] as $u): ?>
        <a href="/profile?username=<?= e($u['username']) ?>" class="form-card" style="display:flex;align-items:center;gap:8px;padding:8px 14px;text-decoration:none;color:inherit">
          <img src="<?= e(user_avatar_url($u, 48)) ?>" alt="" style="width:28px;height:28px;border-radius:50%;object-fit:cover" onerror="this.style.display='none'">
          <span style="font-size:13px"><?= e($u['username']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
