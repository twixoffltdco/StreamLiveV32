<?php
/**
 * Профиль — liquid glass UI (вкладки: посты, видео, ТВ, радио, ресурсы).
 * Все выборки в try/catch — отсутствие таблиц/колонок не роняет страницу.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user_display.php';
user_display_ensure_schema();
if (is_file(__DIR__ . '/includes/contacts.php')) {
  require_once __DIR__ . '/includes/contacts.php';
}
require_once __DIR__ . '/includes/gamification.php';

$__user = current_user();
try { ensure_user_gravatar_column(); } catch (Throwable $e) {}
// Пишем сессию ДО выборки профиля — иначе на своём профиле last_seen ещё старый
if ($__user && function_exists('user_touch_session')) {
  try { user_touch_session($__user); } catch (Throwable $e) {}
}

/** Self-healing: обложка и статус профиля (миграция 038) */

function profile_days_word(int $n): string {
  $n = abs($n) % 100; $n1 = $n % 10;
  if ($n > 10 && $n < 20) return 'дней';
  if ($n1 === 1) return 'день';
  if ($n1 >= 2 && $n1 <= 4) return 'дня';
  return 'дней';
}
function profile_months_word(int $n): string {
  $n = abs($n) % 100; $n1 = $n % 10;
  if ($n > 10 && $n < 20) return 'месяцев';
  if ($n1 === 1) return 'месяц';
  if ($n1 >= 2 && $n1 <= 4) return 'месяца';
  return 'месяцев';
}
function profile_years_word(int $n): string {
  $n = abs($n) % 100; $n1 = $n % 10;
  if ($n > 10 && $n < 20) return 'лет';
  if ($n1 === 1) return 'год';
  if ($n1 >= 2 && $n1 <= 4) return 'года';
  return 'лет';
}
function profile_membership_label(?string $createdAt): string {
  if (!$createdAt) return '';
  $start = strtotime($createdAt);
  if (!$start) return '';
  $now = time();
  if ($start > $now) $start = $now;
  $days = (int)floor(($now - $start) / 86400);
  if ($days < 1) return 'на платформе сегодня · с ' . date('d.m.Y', $start);
  if ($days < 30) return 'на платформе ' . $days . ' ' . profile_days_word($days) . ' · с ' . date('d.m.Y', $start);
  $months = (int)floor($days / 30);
  if ($months < 12) {
    return 'на платформе ' . $months . ' ' . profile_months_word($months) . ' · с ' . date('d.m.Y', $start);
  }
  $years = (int)floor($days / 365);
  $remMonths = (int)floor(($days % 365) / 30);
  $s = 'на платформе ' . $years . ' ' . profile_years_word($years);
  if ($remMonths > 0) $s .= ' ' . $remMonths . ' ' . profile_months_word($remMonths);
  return $s . ' · с ' . date('d.m.Y', $start);
}
function profile_session_label(): string {
  if (session_status() === PHP_SESSION_NONE) @session_start();
  if (empty($_SESSION['sl_sess_start'])) {
    $_SESSION['sl_sess_start'] = time();
  }
  $sec = max(0, time() - (int)$_SESSION['sl_sess_start']);
  if ($sec < 60) return 'эта сессия: только что';
  $min = (int)floor($sec / 60);
  if ($min < 60) return 'эта сессия: ' . $min . ' мин';
  $h = (int)floor($min / 60);
  $m = $min % 60;
  return 'эта сессия: ' . $h . ' ч ' . $m . ' мин';
}


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

// Базовый SELECT (только колонки, которые есть почти всегда).
// Опциональные поля (username_css, session_*, cover, status) подтягиваем ОТДЕЛЬНО —
// иначе один отсутствующий столбец роняет весь запрос и ник остаётся белым.
$profileUser = null;
try {
  $stmt = db()->prepare(
    'SELECT id, username, avatar, gravatar_email, role, created_at, is_verified, is_banned, xp
     FROM users WHERE username = ? LIMIT 1'
  );
  $stmt->execute([$username]);
  $profileUser = $stmt->fetch() ?: null;
} catch (Throwable $e) {
  $profileUser = null;
}

if ($profileUser) {
  $uidTmp = (int)$profileUser['id'];
  $optionalCols = [
    'prefix_id', 'username_css', 'phone',
    'profile_cover_url', 'profile_status_text',
    'nick_decor_url', 'nick_decor_pos',
    'custom_prefix_id', 'custom_prefix_changed_at',
    'session_started_at', 'last_seen_at', 'session_seconds',
  ];
  foreach ($optionalCols as $col) {
    $profileUser[$col] = $profileUser[$col] ?? ($col === 'session_seconds' ? 0 : null);
    try {
      $st = db()->prepare("SELECT `{$col}` FROM users WHERE id = ? LIMIT 1");
      $st->execute([$uidTmp]);
      $val = $st->fetchColumn();
      if ($val !== false) {
        $profileUser[$col] = ($col === 'session_seconds') ? (int)$val : $val;
      }
    } catch (Throwable $e) {
      // колонки нет — оставляем null/0
    }
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
$viewerId = $__user ? (int)$__user['id'] : 0;
$uidProfile = (int)$profileUser['id'];
$iBlockedThem = false;
$theyBlockedMe = false;
$inMyContacts = false;
$mutualContacts = false;
if (function_exists('contacts_ensure_schema')) {
  contacts_ensure_schema();
  if ($viewerId) {
    $iBlockedThem = contacts_is_blocked($viewerId, $uidProfile);
    $theyBlockedMe = contacts_is_blocked($uidProfile, $viewerId);
    $inMyContacts = contacts_is_contact($viewerId, $uidProfile);
    $mutualContacts = contacts_are_mutual($viewerId, $uidProfile);
  }
}

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
    redirect('/profile?username=' . rawurlencode($profileUser['username']));
  }

  if ($action === 'status') {
    profile_glass_ensure_schema();
    $st = mb_substr(trim((string)($_POST['profile_status_text'] ?? '')), 0, 120);
    try {
      db()->prepare('UPDATE users SET profile_status_text = ? WHERE id = ?')->execute([$st !== '' ? $st : null, $uid]);
      flash_set('success', 'Статус обновлён');
    } catch (Throwable $e) {
      flash_set('error', 'Не удалось сохранить статус (колонка profile_status_text). Залей sql/migrations/038_profile_glass.sql');
    }
    redirect('/profile?username=' . rawurlencode($profileUser['username']));
  }

  if ($action === 'cover') {
    profile_glass_ensure_schema();
    $cover = trim((string)($_POST['profile_cover_url'] ?? ''));
    // Разрешаем http(s) URL; filter_var иногда режет валидные ссылки с кириллицей/query
    $okUrl = ($cover === '') || (bool)preg_match('#^https?://[^\s<>"\']{4,}$#iu', $cover);
    if (!$okUrl) {
      flash_set('error', 'Обложка: нужна ссылка http(s)://…');
    } else {
      try {
        db()->prepare('UPDATE users SET profile_cover_url = ? WHERE id = ?')->execute([$cover !== '' ? $cover : null, $uid]);
        flash_set('success', $cover !== '' ? 'Обложка обновлена' : 'Обложка сброшена');
      } catch (Throwable $e) {
        flash_set('error', 'Не удалось сохранить обложку (колонка profile_cover_url). Залей sql/migrations/038_profile_glass.sql');
      }
    }
    redirect('/profile?username=' . rawurlencode($profileUser['username']));
  }

  if ($action === 'save_nick_css') {
    user_display_ensure_schema();
    $css = user_sanitize_nick_css((string)($_POST['username_css'] ?? ''));
    try {
      db()->prepare('UPDATE users SET username_css = ? WHERE id = ?')->execute([$css !== '' ? $css : null, $uid]);
      flash_set('success', $css !== '' ? 'CSS ника сохранён (анимации и классы поддерживаются)' : 'CSS ника сброшен');
    } catch (Throwable $e) {
      flash_set('error', 'Не удалось сохранить CSS ника');
    }
    redirect('/profile?username=' . rawurlencode($profileUser['username']));
  }

  if ($action === 'save_nick_decor') {
    user_display_ensure_schema();
    $url = trim((string)($_POST['nick_decor_url'] ?? ''));
    $pos = strtolower(trim((string)($_POST['nick_decor_pos'] ?? 'before')));
    if ($pos !== 'after') $pos = 'before';
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
      flash_set('error', 'Декор: нужна ссылка http(s) на gif/png/webp');
    } else {
      try {
        db()->prepare('UPDATE users SET nick_decor_url = ?, nick_decor_pos = ? WHERE id = ?')
          ->execute([$url !== '' ? $url : null, $pos, $uid]);
        flash_set('success', $url !== '' ? 'Декор ника сохранён' : 'Декор сброшен');
      } catch (Throwable $e) {
        flash_set('error', 'Не удалось сохранить декор');
      }
    }
    redirect('/profile?username=' . rawurlencode($profileUser['username']));
  }

  if ($action === 'save_custom_prefix') {
    user_display_ensure_schema();
    $res = user_save_custom_prefix(
      $uid,
      (string)($_POST['prefix_title'] ?? ''),
      (string)($_POST['prefix_text_color'] ?? '#ffffff'),
      (string)($_POST['prefix_bg_color'] ?? '#6366f1'),
      (string)($_POST['prefix_css'] ?? '')
    );
    if ($res['ok']) flash_set('success', 'Свой префикс сохранён (следующая смена через 30 дней)');
    else flash_set('error', $res['error'] ?? 'Ошибка');
    redirect('/profile?username=' . rawurlencode($profileUser['username']));
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

// Обновим session_* перед показом (после touch в header ещё раз подтянем)
if (function_exists('user_fetch_session_fields')) {
  $profileUser = array_merge($profileUser, user_fetch_session_fields((int)$profileUser['id']));
}

$avatarUrl = user_avatar_url($profileUser, 192);
$coverUrl = trim((string)($profileUser['profile_cover_url'] ?? ''));
// НЕ подставляем аватар как обложку — иначе кажется, что обложка «сломалась»
$hasCustomCover = ($coverUrl !== '');
$statusText = trim((string)($profileUser['profile_status_text'] ?? ''));
$phone = trim((string)($profileUser['phone'] ?? ''));
// телефон показываем только себе (приватность)
$showPhone = ($phone !== '') && ($isOwnProfile || ($viewerId && function_exists('contacts_can_see_phone') && contacts_can_see_phone($viewerId, $uidProfile, false)));

// Что смотрит прямо сейчас (последний page_view за 5 мин)
$watchingNow = null;
try {
  if (is_file(__DIR__ . '/includes/stats.php')) {
    require_once __DIR__ . '/includes/stats.php';
  }
  $since = date('Y-m-d H:i:s', time() - 300);
  $st = db()->prepare(
    'SELECT path, created_at FROM page_views
     WHERE user_id = ? AND created_at >= ?
     ORDER BY id DESC LIMIT 1'
  );
  $st->execute([$uid, $since]);
  $pv = $st->fetch();
  if ($pv && function_exists('online_path_label')) {
    $watchingNow = online_path_label((string)$pv['path']);
  } elseif ($pv) {
    $watchingNow = (string)$pv['path'];
  }
} catch (Throwable $e) {}

$pageTitle = '@' . $profileUser['username'];
$seoImage = $avatarUrl;
$extraHead = '<link rel="stylesheet" href="/assets/css/profile-glass.css?v=20260801edit1';

require_once __DIR__ . '/includes/header.php';
// если шаблон не выводит $extraHead — подстрахуемся
if (strpos($extraHead, 'profile-glass') !== false) {
  echo $extraHead;
}
?>
<div class="pg-wrap">

  <div class="pg-hero">
    <div class="pg-hero-bg"<?= $hasCustomCover
      ? ' style="background-image:url(\'' . e($coverUrl) . '\')"'
      : ' style="background:linear-gradient(135deg,#1e1b4b 0%,#4c1d95 45%,#0f172a 100%)"' ?>></div>
    <div class="pg-hero-top">
      <a class="pg-icon-btn" href="javascript:history.back()" title="Назад" aria-label="Назад">←</a>
      <?php if ($isOwnProfile): /* edit panel */ ?>
        <a class="pg-icon-btn pg-edit-btn" href="#pg-edit-panel" title="Редактировать профиль" id="pg-edit-open">Edit</a>
      <?php else: ?>
        <span class="pg-icon-btn" style="opacity:.35;pointer-events:none">···</span>
      <?php endif; ?>
    </div>

    <div class="pg-hero-main">
      <img class="pg-avatar" src="<?= e($avatarUrl) ?>" alt="" width="96" height="96" onerror="this.style.opacity='.3'">

      <div class="pg-name-row">
        <?php
          if (function_exists('user_enrich_display_fields')) {
            $profileUser = user_enrich_display_fields($profileUser);
          }
        ?>
        <div class="pg-name-cluster">
          <span class="pg-name-text"><?= function_exists('user_render_username_html') ? user_render_username_html($profileUser) : e($profileUser['username']) ?></span>
          <?= function_exists('verify_badge') ? verify_badge((bool)($profileUser['is_verified'] ?? false)) : '' ?>
        </div>
        <?php if (!empty($usernameHistory)): ?>
          <button type="button" id="uh-toggle" class="xf-name-history-btn" title="История ников" aria-label="История ников">⏱</button>
        <?php endif; ?>
      </div>

      <div class="pg-sub">
        <?= e(profile_membership_label($profileUser['created_at'] ?? null)) ?>
      <?php if (!empty($isOwnProfile) && empty($profileUser['is_verified'])): ?>
        <div class="pg-verify-cta">
          Нет галочки «доверенный».
          <a href="/verification_request.php">Подать заявку на верификацию</a>
          — рассмотрят модераторы.
        </div>
      <?php endif; ?>

        <div class="pg-sub" style="opacity:.85;margin-top:4px"><?= e(user_session_label_from_row($profileUser)) ?></div>
        <?php if ($watchingNow): ?>
          <div class="pg-sub" style="opacity:.9;margin-top:2px">👁 сейчас: <?= e($watchingNow) ?></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($profileUser['is_banned'])): ?>
        <div class="pg-glass" style="margin:12px auto 0;max-width:420px;border-color:rgba(255,80,80,.4);background:rgba(120,20,20,.5);text-align:left">
          <div style="font-size:14px;font-weight:700;color:#ffb4b4;margin-bottom:6px">🚫 Аккаунт заблокирован на платформе</div>
          <div style="font-size:12.5px;color:rgba(255,255,255,.85);line-height:1.45">
            Данный пользователь заблокирован на платформе.
            Администрация платформы <b>не несёт ответственности</b> за действия
            пользователя вне платформы.
          </div>
        </div>
      <?php endif; ?>
      <div class="pg-rank">[<?= e($rank['title'] ?? 'Участник') ?>]</div>

      <div class="pg-actions">
        <?php if ($__user && !$isOwnProfile): ?>
          <?php if (empty($iBlockedThem) && empty($theyBlockedMe)): ?>
            <a class="pg-action" href="/messages.php?with=<?= (int)$profileUser['id'] ?>" title="Написать">💬</a>
          <?php endif; ?>
        <?php elseif ($isOwnProfile): ?>
          <a class="pg-action" href="/messages.php" title="Мессенджер">💬</a>
        <?php else: ?>
          <a class="pg-action" href="/auth/login.php" title="Войти, чтобы написать">💬</a>
        <?php endif; ?>
        <a class="pg-action" href="/catalog.php" title="Каталог">📺</a>
        <a class="pg-action" href="#pg-tabs" id="pg-scroll-tabs" title="Контент">🔍</a>
        <a class="pg-action" href="<?= $isOwnProfile ? '/account_settings.php' : '/dashboard.php' ?>" title="<?= $isOwnProfile ? 'Настройки' : 'Ещё' ?>">···</a>
      </div>

      <?php if (!empty($statusText)): ?>
        <div class="pg-status">♪ <?= e($statusText) ?></div>
      <?php elseif ($isOwnProfile): ?>
        <div class="pg-status" style="opacity:.7">♪ Добавь статус в настройках ниже</div>
      <?php endif; ?>

      <?php if (!empty($usernameHistory)): ?>
        <div id="uh-popup" class="pg-uh-popup" hidden role="dialog" aria-label="История ников">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <b style="font-size:13px;color:#fff">История ников</b>
            <button type="button" id="uh-close" style="background:none;border:none;color:rgba(255,255,255,.55);font-size:20px;cursor:pointer;line-height:1" aria-label="Закрыть">×</button>
          </div>
          <ul style="margin:0;padding:0 4px 4px 18px;font-size:12.5px;color:rgba(255,255,255,.7);line-height:1.55;max-height:240px;overflow:auto">
            <?php foreach ($usernameHistory as $h): ?>
              <li style="margin-bottom:6px">
                <span style="text-decoration:line-through;opacity:.7"><?= e($h['old_username']) ?></span>
                → <b style="color:#fff"><?= e($h['new_username']) ?></b>
                <span style="opacity:.55"> · <?= e(date('d.m.Y', strtotime($h['changed_at']))) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
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
    <div class="pg-glass-row" id="pg-edit-panel" style="flex-direction:column;align-items:stretch;gap:10px">
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
          <input type="text" name="profile_cover_url" value="<?= e($profileUser['profile_cover_url'] ?? '') ?>"
            placeholder="https://example.com/cover.jpg"
            inputmode="url" autocomplete="off"
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
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_nick_css">
        <span class="pg-glass-label">CSS ника (inline или блок с @keyframes / .class — видно в профиле и мини-профиле)</span>
        <textarea name="username_css" rows="8" placeholder="@keyframes neon-pulse { ... }&#10;.glitch-nick { animation: neon-pulse 1.5s infinite; color:#ff00cc; }"
          style="width:100%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:#fff;margin-top:4px;font-family:ui-monospace,monospace;font-size:12px"><?= e($profileUser['username_css'] ?? '') ?></textarea>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px">Сохранить стиль ника</button>
      </form>
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_nick_decor">
        <span class="pg-glass-label">Декор ника (GIF/PNG URL)</span>
        <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap">
          <input type="text" name="nick_decor_url" value="<?= e($profileUser['nick_decor_url'] ?? '') ?>"
            placeholder="https://…/sparkle.gif"
            style="flex:1;min-width:160px;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:#fff">
          <select name="nick_decor_pos" style="padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:#fff">
            <option value="before" <?= (($profileUser['nick_decor_pos'] ?? 'before') === 'before') ? 'selected' : '' ?>>перед ником</option>
            <option value="after" <?= (($profileUser['nick_decor_pos'] ?? '') === 'after') ? 'selected' : '' ?>>после ника</option>
          </select>
          <button type="submit" class="btn btn-outline btn-sm">OK</button>
        </div>
      </form>
      <?php
        $canPfx = function_exists('user_can_edit_custom_prefix') && user_can_edit_custom_prefix($profileUser);
        $nextPfx = function_exists('user_custom_prefix_next_date') ? user_custom_prefix_next_date($profileUser) : null;
        $myPfx = null;
        if (!empty($profileUser['custom_prefix_id']) && function_exists('user_get_prefix')) {
          $myPfx = user_get_prefix((int)$profileUser['custom_prefix_id']);
        }
      ?>
      <form method="post" style="margin:0;opacity:<?= $canPfx ? '1' : '.75' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_custom_prefix">
        <span class="pg-glass-label">Свой префикс (1 шт, смена раз в 30 дней)</span>
        <?php if (!$canPfx && $nextPfx): ?>
          <div style="font-size:12px;opacity:.7;margin:4px 0">Следующая смена: <?= e($nextPfx) ?></div>
        <?php endif; ?>
        <input type="text" name="prefix_title" maxlength="40" value="<?= e($myPfx['title'] ?? '') ?>"
          placeholder="VIP / Author / …" <?= $canPfx ? '' : 'readonly' ?>
          style="width:100%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:#fff;margin-top:4px">
        <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">
          <label style="font-size:12px">текст <input type="color" name="prefix_text_color" value="<?= e($myPfx['text_color'] ?? '#ffffff') ?>" <?= $canPfx ? '' : 'disabled' ?>></label>
          <label style="font-size:12px">фон <input type="color" name="prefix_bg_color" value="<?= e($myPfx['bg_color'] ?? '#6366f1') ?>" <?= $canPfx ? '' : 'disabled' ?>></label>
        </div>
        <input type="text" name="prefix_css" value="<?= e($myPfx['css'] ?? '') ?>" placeholder="свой CSS префикса (опц.)" <?= $canPfx ? '' : 'readonly' ?>
          style="width:100%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:#fff;margin-top:6px">
        <?php if ($canPfx): ?>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px">Сохранить префикс</button>
        <?php endif; ?>
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

  
  <?php if ($__user && !$isOwnProfile): ?>
  <div class="pg-glass" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <?php if ($theyBlockedMe): ?>
      <span style="font-size:13px;color:rgba(255,255,255,.6)">Пользователь ограничил доступ к профилю</span>
    <?php elseif ($iBlockedThem): ?>
      <span style="font-size:13px;color:rgba(255,255,255,.6)">Вы заблокировали этого пользователя</span>
      <button type="button" class="btn btn-outline btn-sm" id="pg-unblock" data-uid="<?= (int)$profileUser['id'] ?>">Разблокировать</button>
    <?php else: ?>
      <?php if ($inMyContacts): ?>
        <button type="button" class="btn btn-outline btn-sm" id="pg-contact" data-uid="<?= (int)$profileUser['id'] ?>" data-act="remove">Удалить из контактов</button>
      <?php else: ?>
        <button type="button" class="btn btn-primary btn-sm" id="pg-contact" data-uid="<?= (int)$profileUser['id'] ?>" data-act="add">В контакты</button>
      <?php endif; ?>
      <?php if ($mutualContacts): ?>
        <span style="font-size:12px;opacity:.7">✓ взаимные контакты<?= $phone !== '' ? ' · телефон виден' : '' ?></span>
      <?php endif; ?>
      <button type="button" class="btn btn-danger btn-sm" id="pg-block" data-uid="<?= (int)$profileUser['id'] ?>">Заблокировать</button>
      <button type="button" class="btn btn-outline btn-sm" id="pg-spam" data-uid="<?= (int)$profileUser['id'] ?>">Спам</button>
    <?php endif; ?>
  </div>
  <script>
  (function(){
    function post(url, body){
      return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body),credentials:'same-origin'})
        .then(function(r){return r.json().catch(function(){return {ok:false};});});
    }
    function on(id, fn){ var el=document.getElementById(id); if(el) el.addEventListener('click', fn); }
    on('pg-contact', function(){
      var b=this, uid=+b.getAttribute('data-uid'), act=b.getAttribute('data-act')||'add';
      b.disabled=true;
      post('/contact_action.php',{action:act,user_id:uid}).then(function(d){ if(d&&d.ok) location.reload(); else { alert((d&&d.error)||'Ошибка'); b.disabled=false; } });
    });
    on('pg-block', function(){
      if(!confirm('Заблокировать пользователя?')) return;
      var b=this, uid=+b.getAttribute('data-uid'); b.disabled=true;
      post('/user_block_action.php',{action:'block',user_id:uid}).then(function(d){ if(d&&d.ok) location.reload(); else { alert((d&&d.error)||'Ошибка'); b.disabled=false; } });
    });
    on('pg-unblock', function(){
      var b=this, uid=+b.getAttribute('data-uid'); b.disabled=true;
      post('/user_block_action.php',{action:'unblock',user_id:uid}).then(function(d){ if(d&&d.ok) location.reload(); else { alert((d&&d.error)||'Ошибка'); b.disabled=false; } });
    });
    on('pg-spam', function(){
      if(!confirm('Пожаловаться на спам?')) return;
      var b=this, uid=+b.getAttribute('data-uid'); b.disabled=true;
      post('/user_report_action.php',{user_id:uid,reason:'spam',severity:'medium'}).then(function(d){ alert((d&&(d.message||d.error))||'Готово'); b.disabled=false; });
    });
  })();
  </script>
  <?php endif; ?>

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
      tabs.forEach(function (x) { x.classList.toggle('active', x === tab); });
      panels.forEach(function (p) {
        p.classList.toggle('active', p.id === 'pg-panel-' + id);
      });
    });
  });

  // скролл к контенту
  var scrollTabs = document.getElementById('pg-scroll-tabs');
  if (scrollTabs) {
    scrollTabs.addEventListener('click', function (e) {
      var t = document.getElementById('pg-tabs');
      if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    });
  }

  // История ников (XenForo-style) — попап у кнопки ⏱
  var btn = document.getElementById('uh-toggle');
  var pop = document.getElementById('uh-popup');
  var closeBtn = document.getElementById('uh-close');

  function placePopup() {
    if (!btn || !pop) return;
    var r = btn.getBoundingClientRect();
    var pad = 8;
    pop.style.position = 'fixed';
    pop.style.zIndex = '10050';
    var wasHidden = pop.hidden;
    if (wasHidden) {
      pop.style.visibility = 'hidden';
      pop.hidden = false;
    }
    var pw = pop.offsetWidth || 280;
    var ph = pop.offsetHeight || 160;
    if (wasHidden) {
      pop.hidden = true;
      pop.style.visibility = '';
    }
    var left = Math.min(window.innerWidth - pw - pad, Math.max(pad, r.left + r.width / 2 - pw / 2));
    var top = r.bottom + 10;
    if (top + ph > window.innerHeight - pad) {
      top = Math.max(pad, r.top - ph - 10);
    }
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
    pop.style.right = 'auto';
    pop.style.transform = 'none';
  }

  function openUh() {
    if (!pop) return;
    placePopup();
    pop.hidden = false;
  }
  function closeUh() {
    if (pop) pop.hidden = true;
  }

  if (btn && pop) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (pop.hidden) openUh(); else closeUh();
    });
  }
  if (closeBtn) {
    closeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      closeUh();
    });
  }
  document.addEventListener('click', function (e) {
    if (!pop || pop.hidden) return;
    if (pop.contains(e.target)) return;
    if (btn && (e.target === btn || btn.contains(e.target))) return;
    closeUh();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeUh();
  });
  window.addEventListener('resize', function () {
    if (pop && !pop.hidden) placePopup();
  });
})();
</script>

<div class="pg-session-foot" style="max-width:960px;margin:24px auto 8px;padding:12px 16px;text-align:center;font-size:12.5px;color:rgba(255,255,255,.45)">
  <?= e(user_session_label_from_row($profileUser)) ?>
  <?php if ($watchingNow): ?> · 👁 <?= e($watchingNow) ?><?php endif; ?>
  · <?= e(profile_membership_label($profileUser['created_at'] ?? null)) ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
