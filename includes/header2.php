<?php
require_once __DIR__ . '/auth.php';
$__user = current_user();
$__flash = flash_get();

// Начисление опыта за вход — срабатывает само на любой странице, если сегодня
// ещё не засчитано. Не требует правки login_user() и работает даже если сессия
// была открыта ещё до входа (частая причина, почему xp оставался на нуле).
$__gamification_today = null;
if ($__user) {
  require_once __DIR__ . '/gamification.php';
  $__gamification_today = register_daily_activity((int)$__user['id']);
}

$pageTitle = $pageTitle ?? SITE_NAME;
$seoDescription = $seoDescription ?? 'StreamLive — платформа для создания онлайн телеканалов и радио';
$seoKeywords = $seoKeywords ?? '';
$seoImage = $seoImage ?? null;
$__canonical = SITE_URL . ($_SERVER['REQUEST_URI'] ?? '/');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> — <?= e(SITE_NAME) ?></title>
  <meta name="description" content="<?= e($seoDescription) ?>">
  <?php if ($seoKeywords): ?><meta name="keywords" content="<?= e($seoKeywords) ?>"><?php endif; ?>
  <link rel="canonical" href="<?= e($__canonical) ?>">
  <meta property="og:title" content="<?= e($pageTitle) ?>">
  <meta property="og:description" content="<?= e($seoDescription) ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= e($__canonical) ?>">
  <?php if ($seoImage): ?><meta property="og:image" content="<?= e($seoImage) ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
  <meta name="robots" content="index, follow">
  <?php $__cssPath = __DIR__ . '/../assets/css/style.css'; $__cssVer = file_exists($__cssPath) ? filemtime($__cssPath) : time(); ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= $__cssVer ?>">
  <?php if (!empty($extraHead)) echo $extraHead; ?>
<?php require_once __DIR__ . '/themes.php'; $__activeTheme = themes_active(); if ($__activeTheme): ?><link rel="stylesheet" href="<?= e($__activeTheme['css']) ?>?v=<?= time() ?>"><?php endif; ?>
</head>
<body>
<?php $__themesList = themes_all(); if ($__themesList): ?><div class="theme-switcher"><select onchange="document.cookie='site_theme='+this.value+'; path=/; max-age=31536000'; location.reload()"><option value="">Themes</option><?php foreach ($__themesList as $t): ?><option value="<?= e($t['slug']) ?>" <?= (!empty($__activeTheme) && $__activeTheme['slug']===$t['slug'])?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<nav class="navbar">
  <a href="/" class="brand"><?= e(SITE_NAME) ?></a>
  <div class="nav-links">
    <a href="/forum">Форум</a>
    <a href="/videos">Видео</a>
    <a href="/rating">Рейтинг</a>
    <?php if ($__user): ?>
      <a href="/favorites">Избранное</a>
      <a href="/dashboard">Мои каналы</a>
        <?php
        $__unreadCount = 0;
        try {
          $__unreadStmt = db()->prepare(
            'SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE (c.user_a_id = ? OR c.user_b_id = ?) AND m.sender_id != ? AND m.read_at IS NULL AND m.is_deleted = 0'
          );
          $__unreadStmt->execute([$__user['id'], $__user['id'], $__user['id']]);
          $__unreadCount = (int)$__unreadStmt->fetchColumn();
        } catch (\Throwable $e) {
          // Таблицы сообщений появятся после sql/migrations/004_add_messaging.sql — до этого просто не считаем непрочитанные
        }
      ?>
      <a href="/messages" class="nav-messages">Сообщения<?php if ($__unreadCount > 0): ?><span class="nav-badge"><?= $__unreadCount ?></span><?php endif; ?></a>
      <a href="/profile?username=<?= e($__user['username']) ?>">Профиль</a>
      <a href="/sticker_packs">Стикеры</a>
      <a href="/favorites">⭐ Избранное</a>
      <a href="/services">Сервисы</a>
      <?php if (in_array($__user['role'], ['moderator','admin'], true)): ?><a href="/moderator/index">Модерация каналов</a><?php endif; ?>
      <?php if (function_exists('is_forum_moderator') && is_forum_moderator($__user)): ?><a href="/moderator/forum">Модерация форума</a><?php endif; ?>
      <?php if ($__user['role'] === 'admin'): ?><a href="/admin/index">Админка</a><?php endif; ?>
      <form action="/auth/logout.php" method="POST" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn btn-outline btn-sm" type="submit">Выйти</button>
      </form>
    <?php else: ?>
      <a href="/auth/login.php">Войти</a>
      <a href="/auth/register.php" class="btn btn-primary btn-sm">Регистрация</a>
    <?php endif; ?>
  </div>
</nav>
<?php foreach ($__flash as $type => $msg): ?>
  <div class="container"><div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div></div>
<?php endforeach; ?>
<?php if ($__gamification_today): ?>
  <div class="container">
    <div class="alert alert-success" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span>👋 С возвращением, <b><?= e($__user['username']) ?></b>! За сегодняшний вход +<?= (int)$__gamification_today['xp_gained'] ?> XP<?= $__gamification_today['streak'] > 1 ? ' (серия: ' . (int)$__gamification_today['streak'] . ' дн.)' : '' ?>.</span>
      <a href="/rating" style="color:inherit;text-decoration:underline">Твоё место в рейтинге: #<?= (int)$__gamification_today['new_place'] ?> →</a>
    </div>
  </div>
<?php endif; ?>
<?php
// Реклама не грузится на страницах входа/регистрации/2FA и в админке — она не нужна там
// пользователю, и раньше мешала (перекрывала/дёргала форму) именно на вводе пин-кода 2FA.
$__adPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$__noAdPaths = ['/auth/login.php', '/auth/register.php', '/auth/2fa_setup.php', '/auth/2fa_verify.php', '/auth/logout.php'];
$__showAd = !in_array($__adPath, $__noAdPaths, true) && strpos((string)$__adPath, '/admin/') !== 0;
if ($__showAd): ?>
   <!-- Верхний рекламный блок -->
<div class="topAdPad">
<div id="movie_video"></div>
<script type="text/javascript" src="https://ad-network.tatnet.app/ad.js?v=03208824bea369b060dba1f2083d6a4c" async></script>
 <nav class="site-footer-links">
      <a href="/docs">Документация</a>
      <a href="/legal/terms">Условия использования</a>
      <a href="/legal/privacy">Конфиденциальность</a>
      <a href="/legal/cookies">Cookie</a>
    </nav>
</div>
 <script src= "https://player.twitch.tv/js/embed/v1.js?version=3.1.1"></script>
<?php endif; ?>