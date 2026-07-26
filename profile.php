<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$__user = current_user();
try { ensure_user_gravatar_column(); } catch (Throwable $e) {}

$username = $_GET['username'] ?? '';
$stmt = db()->prepare('SELECT id, username, avatar, gravatar_email, role, created_at, is_verified, is_banned FROM users WHERE username = ?');
$stmt->execute([$username]);
$profileUser = $stmt->fetch();

if (!$profileUser) {
  http_response_code(404);
  $pageTitle = 'Профиль не найден';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Пользователь не найден</h2></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$isOwnProfile = $__user && (int)$__user['id'] === (int)$profileUser['id'];
if ($isOwnProfile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gravatar_email'])) { csrf_verify(); $email = trim($_POST['gravatar_email']); if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) { db()->prepare('UPDATE users SET gravatar_email = ? WHERE id = ?')->execute([$email ?: null, $__user['id']]); redirect('/profile.php?username=' . urlencode($profileUser['username'])); } else { flash_set('error', 'Укажите корректную почту Gravatar.'); } }

// Публичные каналы видны всем; приватные — только самому владельцу (сам решает,
// показывать канал в профиле или нет — переключатель в настройках канала).
if ($isOwnProfile) {
  $stmt = db()->prepare("SELECT * FROM channels WHERE owner_id = ? AND status = 'approved' ORDER BY id DESC");
} else {
  $stmt = db()->prepare("SELECT * FROM channels WHERE owner_id = ? AND status = 'approved' AND is_public = 1 ORDER BY id DESC");
}
$stmt->execute([$profileUser['id']]);
$channels = $stmt->fetchAll();

$stmt = db()->prepare(
  "SELECT bc.* FROM broadcast_channels bc WHERE bc.owner_id = ? ORDER BY bc.id DESC"
);
$bcChannels = [];
try { $stmt->execute([$profileUser['id']]); $bcChannels = $stmt->fetchAll(); } catch (\Throwable $e) {}

$pageTitle = '@' . $profileUser['username'];
$seoImage = user_avatar_url($profileUser, 240);
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="profile-header">
    <img src="<?= e(user_avatar_url($profileUser, 192)) ?>" alt="" class="profile-avatar" onerror="this.style.display='none'">
    <div class="profile-info">
      <h1>@<?= e($profileUser['username']) ?><?= verify_badge((bool)$profileUser['is_verified']) ?></h1>
      <?php if (!empty($profileUser['is_banned'])): ?>
        <div class="alert alert-error" style="margin:10px 0 4px">
          🚫 Этот аккаунт заблокирован на платформе. Мы не несём ответственности за действия
          пользователя вне платформы.
        </div>
      <?php endif; ?>
      <?php if ($__user && (int)$__user['id'] === (int)$profileUser['id']): ?>
        <?= rating_place_banner((int)$profileUser['id']) ?>
      <?php endif; ?>
      <div class="profile-stats">
        <span><b><?= count($channels) ?></b> каналов</span>
        <span><b><?= count($bcChannels) ?></b> публикаций-каналов</span>
        <span>на сайте с <?= e(date('m.Y', strtotime($profileUser['created_at']))) ?></span>
      </div>
      <?php if ($__user && !$isOwnProfile): ?>
        <a href="/messages.php?with=<?= (int)$profileUser['id'] ?>" class="btn btn-primary btn-sm" style="margin-top:10px;display:inline-block;text-decoration:none">Написать</a>
      <?php elseif ($isOwnProfile): ?>
        <a href="/dashboard.php" class="btn btn-outline btn-sm" style="margin-top:10px;display:inline-block;text-decoration:none">Управлять каналами</a><form method="post" style="margin-top:12px"><?= csrf_field() ?><label style="display:block;color:var(--text-dim);font-size:12px">Почта Gravatar для аватарки</label><input type="email" name="gravatar_email" value="<?= e($profileUser['gravatar_email'] ?? '') ?>" placeholder="you@example.com" style="padding:8px;max-width:260px;width:100%"><button class="btn btn-primary btn-sm">Сохранить</button></form>
      <?php endif; ?>
    </div>
  </div>

  <h3 style="margin:26px 0 12px">ТВ / Радио каналы</h3>
  <?php if (!$channels): ?>
    <p style="color:var(--text-dim)"><?= $isOwnProfile ? 'У вас пока нет каналов.' : 'Публичных каналов пока нет.' ?></p>
  <?php else: ?>
    <div class="profile-grid">
      <?php foreach ($channels as $c): ?>
        <a href="/channel-pc.php?slug=<?= e($c['slug']) ?>" class="profile-grid-item">
          <img src="<?= e($c['logo_url'] ?: '/assets/img/avatar-placeholder.png') ?>" alt="" onerror="this.style.display='none'">
          <div class="profile-grid-caption">
            <b><?= e($c['title']) ?></b>
            <?php if ($isOwnProfile && !$c['is_public']): ?><span class="profile-private-badge">🔒 приватный</span><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($bcChannels): ?>
    <h3 style="margin:26px 0 12px">📢 Каналы-рассылки</h3>
    <div class="msg-conv-list" style="max-width:500px">
      <?php foreach ($bcChannels as $bc): ?>
        <a href="/broadcast_channel.php?slug=<?= e($bc['slug']) ?>" class="msg-conv-item">
          <img src="<?= e($bc['avatar_url'] ?: '/assets/img/avatar-placeholder.png') ?>" alt="" onerror="this.style.display='none'">
          <div class="msg-conv-meta"><b><?= e($bc['title']) ?></b></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
