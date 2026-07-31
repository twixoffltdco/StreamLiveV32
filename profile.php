<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gamification.php'; // для get_rank_for_xp()

$__user = current_user();
try { ensure_user_gravatar_column(); } catch (Throwable $e) {}

$username = $_GET['username'] ?? '';
$stmt = db()->prepare('SELECT id, username, avatar, gravatar_email, role, created_at, is_verified, is_banned, xp FROM users WHERE username = ?');
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

// Ранг пользователя
$rank = get_rank_for_xp((int)$profileUser['xp']);

$isOwnProfile = $__user && (int)$__user['id'] === (int)$profileUser['id'];

// История ников (как в XenForo) — публично видна в профиле
$usernameHistory = [];
try {
  $hStmt = db()->prepare(
    'SELECT old_username, new_username, changed_at FROM username_history
     WHERE user_id = ? ORDER BY id DESC LIMIT 30'
  );
  $hStmt->execute([(int)$profileUser['id']]);
  $usernameHistory = $hStmt->fetchAll();
} catch (\Throwable $e) { /* таблицы ещё нет — миграция 034 */ }

if ($isOwnProfile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gravatar_email'])) { csrf_verify(); $email = trim($_POST['gravatar_email']); if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) { db()->prepare('UPDATE users SET gravatar_email = ? WHERE id = ?')->execute([$email ?: null, $__user['id']]); redirect('/profile.php?username=' . urlencode($profileUser['username'])); } else { flash_set('error', 'Укажите корректную почту Gravatar.'); } }

// Публичные каналы видны всем; приватные — только самому владельцу
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
      <h1>
        <span class="rank-prefix">[<?= e($rank['title']) ?>]</span>
        @<?= e($profileUser['username']) ?>
        <?= verify_badge((bool)$profileUser['is_verified']) ?>
      </h1>
      <?php if (!empty($profileUser['is_banned'])): ?>
        <div class="alert alert-error" style="margin:10px 0 4px">
          🚫 Этот аккаунт заблокирован на платформе. Мы не несём ответственности за действия
          пользователя вне платформы.
        </div>
      <?php endif; ?>
      <?php if ($__user && (int)$__user['id'] === (int)$profileUser['id']): ?>
        <?= rating_place_banner((int)$profileUser['id']) ?>
        <div style="margin:6px 0"><a href="/account_settings.php" class="btn btn-outline btn-sm">⚙️ Настройки аккаунта</a></div>
      <?php endif; ?>
      <div class="profile-stats">
        <span><b><?= count($channels) ?></b> каналов</span>
        <span><b><?= count($bcChannels) ?></b> публикаций-каналов</span>
        <span>на сайте с <?= e(date('m.Y', strtotime($profileUser['created_at']))) ?></span>
      </div>
      <?php if ($usernameHistory): ?>
        <div class="username-history-wrap" style="margin-top:10px;position:relative;display:inline-block">
          <button type="button" id="uh-toggle" class="btn btn-outline btn-sm" aria-expanded="false" aria-controls="uh-popup"
            style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px"
            title="История ников">
            <span aria-hidden="true">🕘</span> История ников
            <span style="opacity:.7;font-size:11px">(<?= count($usernameHistory) ?>)</span>
          </button>
          <div id="uh-popup" role="dialog" aria-label="История ников" hidden
            style="position:absolute;left:0;top:calc(100% + 8px);z-index:40;min-width:260px;max-width:min(360px,90vw);
                   background:var(--card,#1a1a1a);border:1px solid var(--border,rgba(255,255,255,.12));
                   border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.45);padding:12px 14px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
              <b style="font-size:13px">История ников</b>
              <button type="button" id="uh-close" style="background:none;border:none;color:var(--text-dim);cursor:pointer;font-size:16px;line-height:1" aria-label="Закрыть">×</button>
            </div>
            <ul style="margin:0;padding-left:18px;font-size:12.5px;color:var(--text-dim);line-height:1.6;max-height:240px;overflow:auto">
              <?php foreach ($usernameHistory as $h): ?>
                <li>
                  <span style="text-decoration:line-through;opacity:.75"><?= e($h['old_username']) ?></span>
                  → <b style="color:var(--text)"><?= e($h['new_username']) ?></b>
                  <span style="opacity:.7"> · <?= e(date('d.m.Y H:i', strtotime($h['changed_at']))) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <script>
        (function(){
          var btn = document.getElementById('uh-toggle');
          var pop = document.getElementById('uh-popup');
          var closeBtn = document.getElementById('uh-close');
          if (!btn || !pop) return;
          function open(){ pop.hidden = false; btn.setAttribute('aria-expanded','true'); }
          function close(){ pop.hidden = true; btn.setAttribute('aria-expanded','false'); }
          function toggle(e){ e.stopPropagation(); if (pop.hidden) open(); else close(); }
          btn.addEventListener('click', toggle);
          if (closeBtn) closeBtn.addEventListener('click', function(e){ e.stopPropagation(); close(); });
          document.addEventListener('click', function(e){
            if (pop.hidden) return;
            if (!pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) close();
          });
          document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
        })();
        </script>
      <?php endif; ?>
      <?php if ($__user && !$isOwnProfile): ?>
        <a href="/messages.php?with=<?= (int)$profileUser['id'] ?>" class="btn btn-primary btn-sm" style="margin-top:10px;display:inline-block;text-decoration:none">Написать</a>
      <?php elseif ($isOwnProfile): ?>
        <a href="/dashboard.php" class="btn btn-outline btn-sm" style="margin-top:10px;display:inline-block;text-decoration:none">Управлять каналами</a>
        <form method="post" style="margin-top:12px"><?= csrf_field() ?><label style="display:block;color:var(--text-dim);font-size:12px">Почта Gravatar для аватарки</label><input type="email" name="gravatar_email" value="<?= e($profileUser['gravatar_email'] ?? '') ?>" placeholder="you@example.com" style="padding:8px;max-width:260px;width:100%"><button class="btn btn-primary btn-sm">Сохранить</button></form>
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