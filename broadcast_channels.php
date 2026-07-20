<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $title = trim($_POST['title'] ?? '');
  $description = trim($_POST['description'] ?? '');
  if (mb_strlen($title) < 3) {
    flash_set('error', 'Название канала — минимум 3 символа');
    redirect('/broadcast_channels.php');
  }
  $slug = slugify($title);
  db()->prepare('INSERT INTO broadcast_channels (owner_id, slug, title, description) VALUES (?, ?, ?, ?)')
    ->execute([$__user['id'], $slug, $title, $description]);
  $newId = (int)db()->lastInsertId();
  // Автор автоматически подписан на свой канал
  db()->prepare('INSERT IGNORE INTO broadcast_subscribers (channel_id, user_id) VALUES (?, ?)')->execute([$newId, $__user['id']]);
  flash_set('success', 'Канал создан!');
  redirect('/broadcast_channel.php?slug=' . urlencode($slug));
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
  $stmt = db()->prepare(
    "SELECT bc.*, (SELECT COUNT(*) FROM broadcast_subscribers WHERE channel_id = bc.id) AS subs
     FROM broadcast_channels bc WHERE bc.title LIKE ? ORDER BY subs DESC LIMIT 40"
  );
  $stmt->execute(['%' . $q . '%']);
} else {
  $stmt = db()->query(
    "SELECT bc.*, (SELECT COUNT(*) FROM broadcast_subscribers WHERE channel_id = bc.id) AS subs
     FROM broadcast_channels bc ORDER BY subs DESC, bc.id DESC LIMIT 40"
  );
}
$channels = $stmt->fetchAll();

$mySubs = [];
$stmt = db()->prepare('SELECT channel_id FROM broadcast_subscribers WHERE user_id = ?');
$stmt->execute([$__user['id']]);
$mySubs = array_column($stmt->fetchAll(), 'channel_id');

$pageTitle = 'Каналы';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin:20px 0">
    <h2 style="margin:0">📢 Каналы</h2>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('create-channel-form').style.display='block'">Создать канал</button>
  </div>

  <div id="create-channel-form" class="form-card form-wide" style="display:none;margin-bottom:20px">
    <h3>Новый канал</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <label>Название</label>
      <input type="text" name="title" required maxlength="150" placeholder="Например: Новости StreamLive">
      <label style="margin-top:10px">Описание</label>
      <textarea name="description" maxlength="500" rows="3" placeholder="О чём канал"></textarea>
      <button class="btn btn-primary" style="margin-top:14px" type="submit">Создать</button>
    </form>
  </div>

  <form method="GET" style="margin-bottom:16px">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск канала…" style="max-width:320px">
  </form>

  <div class="msg-conv-list" style="max-width:600px">
    <?php if (!$channels): ?>
      <p style="color:var(--text-dim)">Каналов пока нет — станьте первым!</p>
    <?php endif; ?>
    <?php foreach ($channels as $c): $subscribed = in_array($c['id'], $mySubs); ?>
      <a href="/broadcast_channel.php?slug=<?= e($c['slug']) ?>" class="msg-conv-item" style="height:auto;padding:12px 8px">
        <img src="<?= e($c['avatar_url'] ?: '/assets/img/avatar-placeholder.png') ?>" alt="" onerror="this.style.display='none'">
        <div class="msg-conv-meta">
          <b><?= e($c['title']) ?> <?php if ($subscribed): ?><span style="color:var(--accent-2);font-size:11px">✓ подписан</span><?php endif; ?></b>
          <span><?= e(mb_strimwidth((string)($c['description'] ?: ''), 0, 60, '…')) ?> · <?= (int)$c['subs'] ?> подписчиков</span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
