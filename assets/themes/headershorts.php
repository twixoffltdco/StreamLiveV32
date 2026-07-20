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
  <?php $__cssPath = __DIR__ . '/../assets/themes/flexdev.css'; $__cssVer = file_exists($__cssPath) ? filemtime($__cssPath) : time(); ?>
  <link rel="stylesheet" href="/assets/css/themes/flexdev.css?v=<?= $__cssVer ?>">
  <?php if (!empty($extraHead)) echo $extraHead; ?>
<?php require_once __DIR__ . '/themes.php'; $__activeTheme = themes_active(); if ($__activeTheme): ?><link rel="stylesheet" href="<?= e($__activeTheme['css']) ?>?v=<?= time() ?>"><?php endif; ?>
</head>
<body>
<?php $__themesList = themes_all(); if ($__themesList): ?><div class="theme-switcher"><select onchange="document.cookie='site_theme='+this.value+'; path=/; max-age=31536000'; location.reload()"><option value="">Themes</option><?php foreach ($__themesList as $t): ?><option value="<?= e($t['slug']) ?>" <?= (!empty($__activeTheme) && $__activeTheme['slug']===$t['slug'])?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<nav class="navbar">
  <a href="/" class="brand">FLEXDEV</a>
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
      <a href="/services">Сервисы</a>
      <?php if (in_array($__user['role'], ['moderator','admin'], true)): ?><a href="/moderator/index">Модерация каналов</a><a href="/moderator/videos">Модерация видео</a><?php endif; ?>
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
</div>
 <script src= "https://player.twitch.tv/js/embed/v1.js?version=3.1.1"></script>
 <meta name="yandex-verification" content="ebe89f0ca4c9912c" />
<!-- Adlook fly -->
	<script src="https://sdk.adlook.tech/inventory/core.js" async type="text/javascript"></script>

<!-- Хост 2917 -->

<script>
(function UTCoreInitialization() {
  if (window.UTInventoryCore) {
    new window.UTInventoryCore({
      type: "sticky",
      host: 5814,
      content: false,
      adaptive: true,
      width: 400,
      height: 225,
      playMode: "autoplay",
      align: "left",
      verticalAlign: "bottom",
      openTo: "open-creativeView",
      infinity: true,
      infinityTimer: 1,
      interfaceType: 0,
      withoutIframe: true,
      mobile: {
        align: "center",
        verticalAlign: "bottom",
        mobileStickyHeight: 25,
      }
    });
    return;
  }
  if (!window.UTInventoryCore) {
    setTimeout(UTCoreInitialization, 100);
  }
})();
</script>
    <!-- /Adlook fly -->  
<script async src="https://statika.mpsuadv.ru/scripts/11138.js"></script>
 
 <!-- защита от копирования -->
<script>
    document.oncontextmenu = function() { return false; };
    document.onkeydown = function(e) {
        if (e.keyCode == 123) {
            return false;
        }
    };
    document.addEventListener("DOMContentLoaded", function() {
        document.body.oncopy = function() { return false; };
    });
</script>

<!-- Адаптация для тв -->
<style>
        /* Основные стили для телевизоров */
        @media (min-width: 1920px) {
            body {
                font-size: 24px; /* Увеличенный размер шрифта */
            }
            button, a {
                font-size: 24px;
                padding: 15px 30px;
            }
        }

        /* Стили для фокусировки элементов */
        button:focus, a:focus {
            outline: 2px solid #007BFF; /* Видимая фокусировка для пульта */
        }
    </style>

<script>
        function isTV() {
            return navigator.userAgent.match(/SmartTV|SmartTV|TV|HbbTV|NetCast|NETTV|Internet\ Appliance|WebTV|Blue\-ray|Boxee|BrightSign|DLNADOC|CE\-HTML|CE\-HTML1|CE\-HTML2|CE\-HTML3|CE\-HTML4|CE\-HTML5|CE\-HTML6|CE\-HTML7|CE\-HTML8|CE\-HTML9|CE\-HTML10|CE\-HTML11|CE\-HTML12|CE\-HTML13|CE\-HTML14|CE\-HTML15|CE\-HTML16|CE\-HTML17|CE\-HTML18|CE\-HTML19|CE\-HTML20|CE\-HTML21|CE\-HTML22|CE\-HTML23|CE\-HTML24|CE\-HTML25|CE\-HTML26|CE\-HTML27|CE\-HTML28|CE\-HTML29|CE\-HTML30|CE\-HTML31|CE\-HTML32|CE\-HTML33|CE\-HTML34|CE\-HTML35|CE\-HTML36|CE\-HTML37|CE\-HTML38|CE\-HTML39|CE\-HTML40|CE\-HTML41|CE\-HTML42|CE\-HTML43|CE\-HTML44|CE\-HTML45|CE\-HTML46|CE\-HTML47|CE\-HTML48|CE\-HTML49|CE\-HTML50|CE\-HTML51|CE\-HTML52|CE\-HTML53|CE\-HTML54|CE\-HTML55|CE\-HTML56|CE\-HTML57|CE\-HTML58|CE\-HTML59|CE\-HTML60|CE\-HTML61|CE\-HTML62|CE\-HTML63|CE\-HTML64|CE\-HTML65|CE\-HTML66|CE\-HTML67|CE\-HTML68|CE\-HTML69|CE\-HTML70|CE\-HTML71|CE\-HTML72|CE\-HTML73|CE\-HTML74|CE\-HTML75|CE\-HTML76|CE\-HTML77|CE\-HTML78|CE\-HTML79|CE\-HTML80|CE\-HTML81|CE\-HTML82|CE\-HTML83|CE\-HTML84|CE\-HTML85|CE\-HTML86|CE\-HTML87|CE\-HTML88|CE\-HTML89|CE\-HTML90|CE\-HTML91|CE\-HTML92|CE\-HTML93|CE\-HTML94|CE\-HTML95|CE\-HTML96|CE\-HTML97|CE\-HTML98|CE\-HTML99|CE\-HTML100/i);
        }

        // Если это телевизор, примените стили для телевизоров
        if (isTV()) {
            document.body.classList.add('tv-mode');
        }
    </script>
 <!-- Клавиатура для тв -->
<?php endif; ?>