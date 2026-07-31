<?php
/**
 * Профиль — liquid glass UI (вкладки: посты, видео, ТВ, радио, ресурсы).
 * Все выборки в try/catch — отсутствие таблиц/колонок не роняет страницу.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gamification.php';

$__user = current_user();
try { ensure_user_gravatar_column(); } catch (Throwable $e) {}

/** Self-healing: обложка и статус профиля (миграция 038) */
function profile_glass_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    if (!function_exists('table_column_exists') || !table_column_exists('users', 'profile_cover_url')) {
      db()->exec('ALTER TABLE users ADD COLUMN profile_cover_url VARCHAR(500) DEFAULT NULL');
    }
  } catch (Throwable $e) { /* нет прав ALTER — залей sql/migrations/038_profile_glass.sql */ }
  try {
    if (!function_exists('table_column_exists') || !table_column_exists('users', 'profile_status_text')) {
      db()->exec('ALTER TABLE users ADD COLUMN profile_status_text VARCHAR(120) DEFAULT NULL');
    }
  } catch (Throwable $e) {}
}
profile_glass_ensure_schema();

$username = trim((string)($_GET['username'] ?? ''));
if ($username === '') {
  http_response_code(404);
  $pageTitle = 'Профиль не найден';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Пользователь не указан</h2></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

// Тянем все возможные колонки; лишние просто будут null, если их нет в SELECT *
try {
  $stmt = db()->prepare(
    'SELECT id, username, avatar, gravatar_email, role, created_at,
            is_verified, is_banned, xp, phone, profile_cover_url, profile_status_text
     FROM users WHERE username = ? LIMIT 1'
  );
  $stmt->execute([$username]);
  $profileUser = $stmt->fetch();
} catch (Throwable $e) {
  // без новых колонок
  $stmt = db()->prepare(
    'SELECT id, username, avatar, gravatar_email, role, created_at, is_verified, is_banned, xp
     FROM users WHERE username = ? LIMIT 1'
  );
  $stmt->execute([$username]);
  $profileUser = $stmt->fetch();
  if ($profileUser) {
    $profileUser['phone'] = $profileUser['phone'] ?? null;
    $profileUser['profile_cover_url'] = null;
    $profileUser['profile_status_text'] = null;
  }
}

if (!$profileUser) {
  http_response_code(404);
  $pageTitle = 'Профиль не найден';
  require_once __DIR__ . '/includes/header.php';
  echo '<div class="container"><div class="empty-state"><h2>Пользователь не найден</h2></div></div>';
  require_once __DIR__ . '/includes/footer.php';
  exit;
}

$rank = function_exists('get_rank_for_xp')
  ? get_rank_for_xp((int)($profileUser['xp'] ?? 0))
  : ['title' => 'Участник'];
$isOwnProfile = $__user && (int)$__user['id'] === (int)$profileUser['id'];
$uid = (int)$profileUser['id'];

// ---- POST: свои настройки профиля ----
if ($isOwnProfile && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'gravatar' && isset($_POST['gravatar_email'])) {
    $email = trim((string)$_POST['gravatar_email']);
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) {
      try {
        db()->prepare('UPDATE users SET gravatar_email = ? WHERE id = ?')->execute([$email ?: null, $uid]);
        flash_set('success', 'Gravatar сохранён');
      } catch (Throwable $e) {
        flash_set('error', 'Не удалось сохранить Gravatar');
      }
    } else {
      flash_set('error', 'Укажите корректную почту Gravatar');
    }
    redirect('/profile.php?username=' . rawurlencode($profileUser['username']));
  }

  if ($action === 'status') {
    $st = mb_substr(trim((string)($_POST['profile_status_text'] ?? '')), 0, 120);
    try {
      db()->prepare('UPDATE users SET profile_status_text = ? WHERE id = ?')->execute([$st !== '' ? $st : null, $uid]);
      flash_set('success', 'Статус обновлён');
    } catch (Throwable $e) {
      flash_set('error', 'Не удалось сохранить статус (нужна миграция 038)');
    }
    redirect('/profile.php?username=' . rawurlencode($profileUser['username']));
  }

  if ($action === 'cover') {
    $cover = trim((string)($_POST['profile_cover_url'] ?? ''));
    if ($cover !== '' && !filter_var($cover, FILTER_VALIDATE_URL)) {
      flash_set('error', 'Обложка: нужна корректная URL-ссылка');
    } else {
      try {
        db()->prepare('UPDATE users SET profile_cover_url = ? WHERE id = ?')->execute([$cover !== '' ? $cover : null, $uid]);
        flash_set('success', 'Обложка обновлена');
      } catch (Throwable $e) {
        flash_set('error', 'Не удалось сохранить обложку (нужна миграция 038)');
      }
    }
    redirect('/profile.php?username=' . rawurlencode($profileUser['username']));
  }
}

// ---- История ников ----
$usernameHistory = [];
try {
  $hStmt = db()->prepare(
    'SELECT old_username, new_username, changed_at FROM username_history
     WHERE user_id = ? ORDER BY id DESC LIMIT 30'
  );
  $hStmt->execute([$uid]);
  $usernameHistory = $hStmt->fetchAll();
} catch (Throwable $e) {}

// ---- Каналы (ТВ / радио) ----
$channelsAll = [];
$channelsTv = [];
$channelsRadio = [];
try {
  if ($isOwnProfile) {
    $stmt = db()->prepare(
      "SELECT * FROM channels WHERE owner_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 100"
    );
  } else {
    $stmt = db()->prepare(
      "SELECT * FROM channels WHERE owner_id = ? AND status = 'approved' AND (is_public = 1 OR is_public IS NULL) ORDER BY id DESC LIMIT 100"
    );
  }
  $stmt->execute([$uid]);
  $channelsAll = $stmt->fetchAll();
  foreach ($channelsAll as $c) {
    $t = strtolower((string)($c['type'] ?? 'tv'));
    if ($t === 'radio') $channelsRadio[] = $c;
    else $channelsTv[] = $c;
  }
} catch (Throwable $e) {
  // без is_public
  try {
    $stmt = db()->prepare(
      "SELECT * FROM channels WHERE owner_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 100"
    );
    $stmt->execute([$uid]);
    $channelsAll = $stmt->fetchAll();
    foreach ($channelsAll as $c) {
      $t = strtolower((string)($c['type'] ?? 'tv'));
      if ($t === 'radio') $channelsRadio[] = $c;
      else $channelsTv[] = $c;
    }
  } catch (Throwable $e2) {}
}

// ---- Broadcast-каналы (рассылки) ----
$bcChannels = [];
try {
  $stmt = db()->prepare('SELECT * FROM broadcast_channels WHERE owner_id = ? ORDER BY id DESC LIMIT 50');
  $stmt->execute([$uid]);
  $bcChannels = $stmt->fetchAll();
} catch (Throwable $e) {}

// ---- Темы форума ----
$forumThreads = [];
try {
  $stmt = db()->prepare(
    "SELECT id, title, views, created_at, last_post_at, is_pinned
     FROM forum_threads
     WHERE user_id = ? AND is_deleted = 0
     ORDER BY last_post_at DESC LIMIT 50"
  );
  $stmt->execute([$uid]);
  $forumThreads = $stmt->fetchAll();
} catch (Throwable $e) {}

// ---- Видео (с каналов пользователя) ----
$videos = [];
try {
  $stmt = db()->prepare(
    "SELECT v.id, v.slug, v.title, v.thumbnail_url, v.views_count, v.created_at, v.platform,
            c.title AS channel_title, c.slug AS channel_slug
     FROM videos v
     JOIN channels c ON c.id = v.channel_id
     WHERE v.user_id = ? AND v.status = 'published'
     ORDER BY v.created_at DESC LIMIT 60"
  );
  $stmt->execute([$uid]);
  $videos = $stmt->fetchAll();
} catch (Throwable $e) {
  try {
    $stmt = db()->prepare(
      "SELECT v.id, v.slug, v.title, v.thumbnail_url, v.views_count, v.created_at, v.platform
       FROM videos v WHERE v.user_id = ? AND v.status = 'published'
       ORDER BY v.created_at DESC LIMIT 60"
    );
    $stmt->execute([$uid]);
    $videos = $stmt->fetchAll();
  } catch (Throwable $e2) {}
}

// ---- Ресурсы ----
$resources = [];
try {
  $stmt = db()->prepare(
    "SELECT id, slug, title, summary, tags, views, created_at
     FROM resources WHERE user_id = ? AND status = 'published'
     ORDER BY created_at DESC LIMIT 40"
  );
  $stmt->execute([$uid]);
  $resources = $stmt->fetchAll();
} catch (Throwable $e) {}

$avatarUrl = user_avatar_url($profileUser, 192);
$coverUrl = trim((string)($profileUser['profile_cover_url'] ?? ''));
if ($coverUrl === '') {
  $coverUrl = $avatarUrl; // фолбэк — аватар размытый как обложка
}
$statusText = trim((string)($profileUser['profile_status_text'] ?? ''));
$phone = trim((string)($profileUser['phone'] ?? ''));
// телефон показываем только себе (приватность)
$showPhone = $isOwnProfile && $phone !== '';

$pageTitle = '@' . $profileUser['username'];
$seoImage = $avatarUrl;
$extraHead = '<link rel="stylesheet" href="/assets/css/profile-glass.css?v=20260731pg1">';

require_once __DIR__ . '/includes/header.php';
// если шаблон не выводит $extraHead — подстрахуемся
if (strpos($extraHead, 'profile-glass') !== false) {
  echo $extraHead;
}
?>
<div class="pg-wrap">

  <div class="pg-hero">
    <div class="pg-hero-bg" style="background-image:url('<?= e($coverUrl) ?>')"></div>
    <div class="pg-hero-top">
      <a class="pg-icon-btn" href="javascript:history.back()" title="Назад" aria-label="Назад">←</a>
      <?php if ($isOwnProfile): ?>
        <a class="pg-icon-btn" href="/account_settings.php" title="Настройки">Edit</a>
      <?php else: ?>
        <span class="pg-icon-btn" style="opacity:.35;pointer-events:none">···</span>
      <?php endif; ?>
    </div>

    <div class="pg-hero-main">
      <img class="pg-avatar" src="<?= e($avatarUrl) ?>" alt="" onerror="this.style.opacity='.3'">
      <div class="pg-name">
        <?= e($profileUser['username']) ?>
        <?= function_exists('verify_badge') ? verify_badge((bool)($profileUser['is_verified'] ?? false)) : '' ?>
      </div>
      <div class="pg-sub">
        <?php if (!empty($profileUser['is_banned'])): ?>
          <span style="color:#f66">заблокирован</span>
        <?php else: ?>
          на сайте с <?= e(date('m.Y', strtotime($profileUser['created_at']))) ?>
        <?php endif; ?>
      </div>
      <div class="pg-rank">[<?= e($rank['title'] ?? 'Участник') ?>]</div>

      <div class="pg-actions">
        <?php if ($__user && !$isOwnProfile): ?>
          <a class="pg-action" href="/messages.php?with=<?= $uid ?>" title="Написать">💬</a>
        <?php elseif ($isOwnProfile): ?>
          <a class="pg-action" href="/messages.php" title="Мессенджер">💬</a>
        <?php else: ?>
          <a class="pg-action" href="/auth/login.php" title="Войти, чтобы написать">💬</a>
        <?php endif; ?>
        <a class="pg-action" href="/catalog.php" title="Каталог">🔔</a>
        <a class="pg-action" href="#pg-tabs" title="Контент">🔍</a>
        <?php if ($usernameHistory): ?>
          <button type="button" class="pg-action" id="uh-toggle" title="История ников" style="border:none;cursor:pointer">🕘</button>
        <?php else: ?>
          <a class="pg-action" href="/dashboard.php" title="Ещё">···</a>
        <?php endif; ?>
      </div>

      <?php if ($statusText !== ''): ?>
        <div class="pg-status">♪ <?= e($statusText) ?></div>
      <?php elseif ($isOwnProfile): ?>
        <div class="pg-status" style="opacity:.7">♪ Добавь статус в настройках ниже</div>
      <?php endif; ?>

      <?php if ($usernameHistory): ?>
        <div style="position:relative;display:inline-block;margin-top:8px">
          <div id="uh-popup" class="pg-uh-popup" hidden>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
              <b style="font-size:13px;color:#fff">История ников</b>
              <button type="button" id="uh-close" style="background:none;border:none;color:rgba(255,255,255,.5);font-size:18px;cursor:pointer">×</button>
            </div>
            <ul style="margin:0;padding-left:18px;font-size:12.5px;color:rgba(255,255,255,.65);line-height:1.55;max-height:220px;overflow:auto">
              <?php foreach ($usernameHistory as $h): ?>
                <li>
                  <span style="text-decoration:line-through;opacity:.7"><?= e($h['old_username']) ?></span>
                  → <b style="color:#fff"><?= e($h['new_username']) ?></b>
                  <span style="opacity:.55"> · <?= e(date('d.m.Y', strtotime($h['changed_at']))) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="pg-glass">
    <div class="pg-glass-row">
      <div>
        <span class="pg-glass-label">username</span>
        <b>@<?= e($profileUser['username']) ?></b>
      </div>
      <span style="opacity:.45;font-size:18px">▣</span>
    </div>
    <?php if ($showPhone): ?>
    <div class="pg-glass-row">
      <div>
        <span class="pg-glass-label">phone</span>
        <b><?= e($phone) ?></b>
      </div>
    </div>
    <?php endif; ?>
    <div class="pg-glass-row">
      <div>
        <span class="pg-glass-label">контент</span>
        <b><?= count($forumThreads) ?> тем · <?= count($videos) ?> видео · <?= count($channelsAll) ?> каналов</b>
      </div>
    </div>
    <?php if ($isOwnProfile): ?>
    <div class="pg-glass-row" style="flex-direction:column;align-items:stretch;gap:10px">
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="status">
        <span class="pg-glass-label">статус (как музыка в мессенджере)</span>
        <div style="display:flex;gap:8px;margin-top:4px">
          <input type="text" name="profile_status_text" maxlength="120" value="<?= e($statusText) ?>"
            placeholder="Что сейчас слушаешь / статус…"
            style="flex:1;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
          <button class="btn btn-primary btn-sm" type="submit">OK</button>
        </div>
      </form>
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cover">
        <span class="pg-glass-label">обложка (URL картинки)</span>
        <div style="display:flex;gap:8px;margin-top:4px">
          <input type="url" name="profile_cover_url" value="<?= e($profileUser['profile_cover_url'] ?? '') ?>"
            placeholder="https://…"
            style="flex:1;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
          <button class="btn btn-outline btn-sm" type="submit">OK</button>
        </div>
      </form>
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="gravatar">
        <span class="pg-glass-label">Gravatar email</span>
        <div style="display:flex;gap:8px;margin-top:4px">
          <input type="email" name="gravatar_email" value="<?= e($profileUser['gravatar_email'] ?? '') ?>"
            placeholder="you@example.com"
            style="flex:1;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:inherit">
          <button class="btn btn-outline btn-sm" type="submit">OK</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <?php
  // геймификация — мягко
  if (is_file(__DIR__ . '/includes/profile_gamification_block.php')) {
    echo '<div style="margin-top:12px">';
    include __DIR__ . '/includes/profile_gamification_block.php';
    echo '</div>';
  }
  ?>

  <div class="pg-tabs" id="pg-tabs" role="tablist">
    <button type="button" class="pg-tab active" data-tab="posts">Посты</button>
    <button type="button" class="pg-tab" data-tab="media">Видео</button>
    <button type="button" class="pg-tab" data-tab="tv">ТВ</button>
    <button type="button" class="pg-tab" data-tab="radio">Радио</button>
    <button type="button" class="pg-tab" data-tab="files">Ресурсы</button>
  </div>

  <!-- Посты (форум) -->
  <div class="pg-panel active" id="pg-panel-posts">
    <?php if (!$forumThreads): ?>
      <div class="pg-empty">Тем на форуме пока нет</div>
    <?php else: ?>
      <div class="pg-list">
        <?php foreach ($forumThreads as $t): ?>
          <a href="/forum_thread.php?id=<?= (int)$t['id'] ?>">
            <div class="pg-list-thumb pg-ph" style="width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#222;font-size:18px">💬</div>
            <div class="pg-list-meta">
              <b><?= e($t['title']) ?><?= !empty($t['is_pinned']) ? ' 📌' : '' ?></b>
              <span><?= (int)($t['views'] ?? 0) ?> просм. · <?= e(date('d.m.Y', strtotime($t['last_post_at'] ?? $t['created_at']))) ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Видео -->
  <div class="pg-panel" id="pg-panel-media">
    <?php if (!$videos): ?>
      <div class="pg-empty">Опубликованных видео пока нет</div>
    <?php else: ?>
      <div class="pg-grid">
        <?php foreach ($videos as $v): ?>
          <a href="/video.php?slug=<?= e($v['slug']) ?>" title="<?= e($v['title']) ?>">
            <?php if (!empty($v['thumbnail_url'])): ?>
              <img src="<?= e($v['thumbnail_url']) ?>" alt="" loading="lazy">
            <?php else: ?>
              <div class="pg-ph">▶</div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ТВ -->
  <div class="pg-panel" id="pg-panel-tv">
    <?php if (!$channelsTv): ?>
      <div class="pg-empty">ТВ-каналов пока нет</div>
    <?php else: ?>
      <div class="pg-grid">
        <?php foreach ($channelsTv as $c): ?>
          <a href="/channel.php?slug=<?= e($c['slug']) ?>" title="<?= e($c['title']) ?>">
            <?php if (!empty($c['logo_url'])): ?>
              <img src="<?= e($c['logo_url']) ?>" alt="" loading="lazy">
            <?php else: ?>
              <div class="pg-ph">📺</div>
            <?php endif; ?>
            <span class="pg-type-pill">ТВ</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Радио -->
  <div class="pg-panel" id="pg-panel-radio">
    <?php if (!$channelsRadio): ?>
      <div class="pg-empty">Радио-каналов пока нет</div>
    <?php else: ?>
      <div class="pg-grid">
        <?php foreach ($channelsRadio as $c): ?>
          <a href="/channel.php?slug=<?= e($c['slug']) ?>" title="<?= e($c['title']) ?>">
            <?php if (!empty($c['logo_url'])): ?>
              <img src="<?= e($c['logo_url']) ?>" alt="" loading="lazy">
            <?php else: ?>
              <div class="pg-ph">📻</div>
            <?php endif; ?>
            <span class="pg-type-pill">Радио</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Ресурсы / файлы -->
  <div class="pg-panel" id="pg-panel-files">
    <?php if (!$resources && !$bcChannels): ?>
      <div class="pg-empty">Ресурсов и рассылок пока нет</div>
    <?php else: ?>
      <div class="pg-list">
        <?php foreach ($resources as $r): ?>
          <a href="/resource.php?slug=<?= e($r['slug']) ?>">
            <div class="pg-list-thumb" style="display:flex;align-items:center;justify-content:center;font-size:20px;background:#222">📎</div>
            <div class="pg-list-meta">
              <b><?= e($r['title']) ?></b>
              <span><?= (int)($r['views'] ?? 0) ?> · <?= e(date('d.m.Y', strtotime($r['created_at']))) ?></span>
            </div>
          </a>
        <?php endforeach; ?>
        <?php foreach ($bcChannels as $bc): ?>
          <a href="/broadcast_channel.php?slug=<?= e($bc['slug']) ?>">
            <img class="pg-list-thumb" src="<?= e($bc['avatar_url'] ?? '/assets/img/avatar-placeholder.png') ?>" alt="" onerror="this.style.display='none'">
            <div class="pg-list-meta">
              <b>📢 <?= e($bc['title']) ?></b>
              <span>канал-рассылка</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
(function () {
  // вкладки
  var tabs = document.querySelectorAll('.pg-tab');
  var panels = document.querySelectorAll('.pg-panel');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var id = tab.getAttribute('data-tab');
      tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
      panels.forEach(function (p) {
        p.classList.toggle('active', p.id === 'pg-panel-' + id);
      });
    });
  });

  // история ников — попап
  var btn = document.getElementById('uh-toggle');
  var pop = document.getElementById('uh-popup');
  var closeBtn = document.getElementById('uh-close');
  if (btn && pop) {
    function open() { pop.hidden = false; }
    function close() { pop.hidden = true; }
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (pop.hidden) open(); else close();
    });
    if (closeBtn) closeBtn.addEventListener('click', function (e) { e.stopPropagation(); close(); });
    document.addEventListener('click', function (e) {
      if (pop.hidden) return;
      if (!pop.contains(e.target) && e.target !== btn) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
