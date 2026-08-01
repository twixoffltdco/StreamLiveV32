<?php
require_once __DIR__ . '/auth.php';
$__user = current_user();

if (is_file(__DIR__ . '/user_display.php')) {
  require_once __DIR__ . '/user_display.php';
  if (function_exists('user_display_ensure_schema')) {
    try { user_display_ensure_schema(); } catch (Throwable $e) {}
  }
  if (!empty($__user) && function_exists('user_touch_session')) {
    try { user_touch_session($__user); } catch (Throwable $e) {}
  }
}

$__flash = flash_get();

// Начисление опыта за вход — срабатывает само на любой странице, если сегодня
// ещё не засчитано. Не требует правки login_user() и работает даже если сессия
// была открыта ещё до входа (частая причина, почему xp оставался на нуле).
$__gamification_today = null;
if ($__user) {
  // Снимаем автостоп задеплоенных сервисов на КАЖДОЙ загрузке страницы залогиненным
  // человеком, а не только в момент входа (login_user()) — раньше было только там, но
  // сессия теперь живёт 30 дней, и человек, который просто продолжает пользоваться сайтом
  // без повторного ввода пароля, никогда не проходил через login_user() повторно. Из-за
  // этого автостоп не снимался неделями, хотя человек всё это время реально на сайте.
  // UPDATE с WHERE по индексированному user_id и suspended=1 — дешёвый запрос, почти всегда
  // затрагивает 0 строк, не страшно гонять на каждой странице.
  try {
    require_once __DIR__ . '/service_helpers.php';
    deployed_services_resume_for_user((int)$__user['id']);
  } catch (\Throwable $e) { }

  require_once __DIR__ . '/gamification.php';
  // Оборачиваем в try/catch: это начисление XP не должно ронять ВЕСЬ САЙТ (все страницы,
  // не только геймификацию), если вдруг разъедется схема БД — например, если на хостинге
  // миграция геймификации (017_gamification_and_moderation.sql) была применена не полностью.
  // Раньше при любой такой рассинхронизации получали 500 на КАЖДОЙ странице сайта.
  try {
    $__gamification_today = register_daily_activity((int)$__user['id']);
  } catch (\Throwable $e) { $__gamification_today = null; }
}

$pageTitle = $pageTitle ?? SITE_NAME;

// Гарантируем колонки gravatar_email/cover_url ГЛОБАЛЬНО на каждой странице, а не точечно
// в паре файлов — иначе любая страница, которая выбирает u.gravatar_email или c.gravatar_email/
// c.cover_url раньше, чем эта функция была вызвана хоть где-то, падает 500 (поймал именно
// так на forum_category.php при добавлении единого render_user_badge()).
try { ensure_user_gravatar_column(); } catch (\Throwable $e) {}
try { ensure_channel_avatar_columns(); } catch (\Throwable $e) {}

$seoDescription = $seoDescription ?? 'StreamLive — платформа для создания онлайн телеканалов и радио';
$seoKeywords = $seoKeywords ?? '';
$seoImage = $seoImage ?? null;
$__canonical = SITE_URL . ($_SERVER['REQUEST_URI'] ?? '/');
?>
<?php @include __DIR__ . '/platforma/header_switcher.php'; ?>
<?php @include dirname(__DIR__) . '/platforma/header_switcher.php'; ?>
<?php @include __DIR__ . '/platforma/recommendations_block.php'; ?>
<!DOCTYPE html>
<html lang="ru" class="<?= ($_COOKIE['site_color_mode'] ?? 'dark') === 'light' ? 'light-mode' : '' ?>">
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
  <?php require_once __DIR__ . '/themes.php'; $__activeTheme = themes_active(); if ($__activeTheme): ?><link rel="stylesheet" href="<?= e($__activeTheme['css']) ?>?v=<?= time() ?>"><?php endif; ?>
  <link rel="stylesheet" href="/assets/css/light-mode.css?v=<?= file_exists(__DIR__ . '/../assets/css/light-mode.css') ? filemtime(__DIR__ . '/../assets/css/light-mode.css') : time() ?>">
  <?php if (!empty($extraHead)) echo $extraHead; ?>
  <link rel="stylesheet" href="/assets/css/user-display.css?v=2">
</head>

<!-- Seasonal effects -->
<script>
function createSnowflake() {
  const snowflake = document.createElement('div');
  snowflake.className = 'snowflake';
  snowflake.textContent = '❄️';
  snowflake.style.left = Math.random() * 100 + 'vw';
  snowflake.style.animationDuration = Math.random() * 3 + 5 + 's';
  snowflake.style.opacity = Math.random() * 0.5 + 0.5;
  snowflake.style.fontSize = Math.random() * 10 + 10 + 'px';
  document.body.appendChild(snowflake);
  setTimeout(() => snowflake.remove(), 10000);
}

const now = new Date();
const month = now.getMonth() + 1;
if (month === 12 || month === 1 || month === 2) { // Winter
  setInterval(createSnowflake, 200);
} else if (month === 6 || month === 7 || month === 8) { // Summer - butterflies or leaves
  function createSummerEffect() {
    const leaf = document.createElement('div');
    leaf.className = 'summer-leaf';
    leaf.textContent = ['🌿', '🦋', '🍃'][Math.floor(Math.random()*3)];
    leaf.style.left = Math.random() * 100 + 'vw';
    leaf.style.animationDuration = Math.random() * 4 + 6 + 's';
    document.body.appendChild(leaf);
    setTimeout(() => leaf.remove(), 12000);
  }
  setInterval(createSummerEffect, 300);
}
</script>
<style>
.snowflake, .summer-leaf {
  position: fixed;
  top: -10px;
  z-index: 9999;
  pointer-events: none;
  animation: fall linear forwards;
}
@keyframes fall {
  to { transform: translateY(100vh); }
}
</style>
<body>
<?php $__themesList = themes_all(); if ($__themesList): ?><div class="theme-switcher"><select onchange="document.cookie='site_theme='+this.value+'; path=/; max-age=31536000'; location.reload()"><option value="">Themes</option><?php foreach ($__themesList as $t): ?><option value="<?= e($t['slug']) ?>" <?= (!empty($__activeTheme) && $__activeTheme['slug']===$t['slug'])?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if (!empty($__activeTheme['header'])) theme_safe_include(themes_dir() . '/' . $__activeTheme['header']); ?>
    <style>
      


        /* Контентная часть — тени для читаемости */
        .banner-content {
            flex: 1 1 70%;
            display: flex;
            flex-direction: column;
            gap: 4px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.9);
        }

        .banner-title {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            background: linear-gradient(90deg, #6fc3ff, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
            text-shadow: 0 0 25px rgba(0, 150, 255, 0.25);
        }

        .banner-text {
            font-size: 0.95rem;
            line-height: 1.4;
            margin: 2px 0 0 0;
            color: #f0f4ff;
            text-shadow: 0 2px 12px rgba(0, 0, 0, 0.95);
        }

        .banner-text strong {
            color: #ffd966;
            font-weight: 600;
            text-shadow: 0 0 15px rgba(255, 200, 0, 0.2);
        }

        .banner-roles {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 22px;
            margin: 4px 0 0 0;
            font-size: 0.88rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.9);
        }

        .banner-roles span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .banner-roles .badge {
            display: inline-block;
            background: rgba(42, 58, 92, 0.6);
            backdrop-filter: blur(4px);
            border-radius: 30px;
            padding: 1px 12px;
            font-size: 0.7rem;
            font-weight: 600;
            color: #b0c8ff;
            letter-spacing: 0.3px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .banner-roles .badge.media {
            background: rgba(61, 42, 92, 0.6);
            color: #d4b0ff;
        }

        .banner-contact {
            margin-top: 2px;
            font-size: 0.9rem;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.9);
        }

        .banner-contact a {
            color: #6fc3ff;
            text-decoration: none;
            font-weight: 600;
            border-bottom: 1px dashed rgba(111, 195, 255, 0.4);
            transition: 0.2s;
        }

        .banner-contact a:hover {
            color: #a78bfa;
            border-bottom-color: #a78bfa;
        }

        /* Кнопки */
        .banner-actions {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: 10px;
        }

        .btn-close {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ccd4e8;
            font-size: 1.6rem;
            line-height: 1;
            cursor: pointer;
            padding: 0 10px;
            border-radius: 30px;
            transition: 0.2s;
            font-weight: 300;
            backdrop-filter: blur(4px);
        }

        .btn-close:hover {
            background: rgba(255, 80, 80, 0.15);
            color: #ff6b6b;
            border-color: #ff6b6b;
            transform: scale(1.1);
        }

        .btn-write {
            background: rgba(42, 74, 122, 0.5);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 6px 16px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.82rem;
            cursor: pointer;
            transition: 0.2s;
            white-space: nowrap;
            text-decoration: none;
            display: inline-block;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .btn-write:hover {
            background: rgba(58, 106, 154, 0.7);
            transform: scale(1.02);
            box-shadow: 0 0 20px rgba(0, 140, 255, 0.15);
        }

        /* Адаптив для мобильных */
        @media (max-width: 650px) {
            .stlive-banner {
                top: 10px;
                padding: 14px 16px;
                width: 95%;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                border-radius: 14px;
            }
            .banner-title { font-size: 1.05rem; }
            .banner-text { font-size: 0.82rem; }
            .banner-roles { font-size: 0.75rem; gap: 6px 14px; }
            .banner-actions { justify-content: flex-end; margin-left: 0; }
            .btn-close { font-size: 1.4rem; padding: 0 8px; }
            .btn-write { padding: 5px 12px; font-size: 0.75rem; }
        }

        @media (max-width: 430px) {
            .banner-roles { flex-direction: column; gap: 3px; }
            .banner-actions { justify-content: space-between; margin-top: 4px; }
        }
    </style>
<nav class="navbar">
  <a href="/" class="brand"><?= e(SITE_NAME) ?></a>
  <form action="/search" method="GET" class="nav-search" style="display:flex;align-items:center">
    <input type="text" name="q" placeholder="Поиск по сайту…" style="padding:7px 10px;font-size:13px;width:150px" value="<?= e($_GET['q'] ?? '') ?>">
  </form>
  <div class="nav-links">
    <a href="/forum">Форум</a>
    <a href="/forum_whats_new.php">Что нового</a>
    <a href="/videos">Видео</a>
    <a href="/resources">Ресурсы</a>
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
      <a href="/playlists">🎞️ Плейлисты</a>
      <a href="/continue_watching">▶️ Продолжить просмотр</a>
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
    <button type="button" id="color-mode-toggle" class="btn btn-outline btn-sm" title="Светлая/тёмная тема" style="margin-left:6px">
      <?= ($_COOKIE['site_color_mode'] ?? 'dark') === 'light' ? '🌙' : '☀️' ?>
    </button>
  </div>
</nav>
<script>
(function () {
  var btn = document.getElementById('color-mode-toggle');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var isLight = document.documentElement.classList.toggle('light-mode');
    document.cookie = 'site_color_mode=' + (isLight ? 'light' : 'dark') + ';path=/;max-age=' + (60 * 60 * 24 * 365);
    btn.textContent = isLight ? '🌙' : '☀️';
  });
})();
</script>
<?php foreach ($__flash as $type => $msg): ?>
  <div class="container"><div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div></div>
<?php endforeach; ?>
<?php if ($__gamification_today): ?>
  <div id="streakModal" class="streak-modal-overlay">
    <div class="streak-modal">
      <button type="button" class="streak-modal-close" onclick="document.getElementById('streakModal').remove()">✕</button>
      <div class="streak-modal-icon">🔥</div>
      <h2 class="streak-modal-title">Ежедневный вход!</h2>
      <p class="streak-modal-streak">Ваша серия: <b><?= (int)$__gamification_today['streak'] ?> дн.</b></p>
      <?php if ((int)$__gamification_today['longest_streak'] > (int)$__gamification_today['streak']): ?>
        <p class="streak-modal-record">🏅 Ваш рекорд: <?= (int)$__gamification_today['longest_streak'] ?> дн. — серия сбрасывается при пропуске дня, но рекорд сохраняется навсегда.</p>
      <?php else: ?>
        <p class="streak-modal-record">🏅 Это ваш личный рекорд!</p>
      <?php endif; ?>
      <p class="streak-modal-hint">Продолжайте заходить каждый день, чтобы получать бонусы опыта!</p>
      <div class="streak-modal-days">
        <?php
          $__dayInWeek = ((int)$__gamification_today['streak'] - 1) % 7; // 0..6, где сегодня
          for ($__d = 0; $__d < 7; $__d++):
            $__cls = $__d < $__dayInWeek ? 'streak-day-done' : ($__d === $__dayInWeek ? 'streak-day-today' : 'streak-day-future');
        ?>
          <span class="streak-day <?= $__cls ?>"><?= $__d < $__dayInWeek ? '★' : ($__d + 1) ?></span>
        <?php endfor; ?>
      </div>
      <p class="streak-modal-xp">+<?= (int)$__gamification_today['xp_gained'] ?> XP за сегодня · <a href="/rating" style="color:inherit">твоё место: #<?= (int)$__gamification_today['new_place'] ?></a></p>
      <button type="button" class="btn btn-primary streak-modal-ok" onclick="document.getElementById('streakModal').remove()">Отлично!</button>
    </div>
  </div>
  <div id="streakToast" class="streak-toast">
    🔥 Серия <?= (int)$__gamification_today['streak'] ?> дней! Вы заходите на платформу <?= (int)$__gamification_today['streak'] ?> дн. подряд!
  </div>
  <script>
    setTimeout(function () { var t = document.getElementById('streakToast'); if (t) t.classList.add('streak-toast-hide'); }, 5000);
  </script>
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

<!--LiveInternet counter--><script>
new Image().src = "https://counter.yadro.ru/hit;hjqwegbrvfshdfd?r"+
escape(document.referrer)+((typeof(screen)=="undefined")?"":
";s"+screen.width+"*"+screen.height+"*"+(screen.colorDepth?
screen.colorDepth:screen.pixelDepth))+";u"+escape(document.URL)+
";h"+escape(document.title.substring(0,150))+
";"+Math.random();</script><!--/LiveInternet-->
<div id="movie_video"></div><script type="text/javascript" src="https://vak345.com/s.js?v=b391b4a023b1ee94545453355338023cbbf13cf81fa" async></script></div>
 <nav class="site-footer-links">
      <a href="/docs">Документация</a>
      <a href="/legal/terms">Условия использования</a>
      <a href="/legal/privacy">Конфиденциальность</a>
      <a href="/legal/cookies">Cookie</a>
        <!-- БАННЕР С ПРОЗРАЧНЫМ ФОНОМ -->
    <div id="stliveBanner" class="stlive-banner">
        <div class="banner-content">
            <div class="banner-title">🚀 Набор в команду STLIVE/FLEXDEV TEAM</div>
            <div class="banner-text">
                Требуются <strong>ответственные модераторы</strong> для развития проекта.
            </div>
            <div class="banner-roles">
                <span>
                    <span class="badge">🖥️ ФОРУМ</span>
                    Контроль порядка, общение, помощь пользователям
                </span>
                <span>
                    <span class="badge media">📺 МЕДИА</span>
                    ТВ, Радио, видео и аудио контент
                </span>
            </div>
            <div class="banner-contact">
                ✉️ По вопросам пишите в ЛС: <a href="/profile?username=admin"; return false;">@admin</a>
            </div>
        </div>
        <div class="banner-actions">
            <a href="/profile?username=admin" class="btn-write"; return false;">Написать</a>
    </nav>
</div>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Шрифт Inter (современный гротеск) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        /* Основной контейнер баннера */
        .banner {
            position: relative;
            max-width: 1200px;
            width: 100%;
            aspect-ratio: 16 / 7; /* пропорции для баннера */
            background: linear-gradient(135deg, #0055FF 0%, #9B51E0 100%);
            border-radius: 32px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 40px 60px;
            box-shadow: 0 30px 60px rgba(0, 85, 255, 0.3);
            transition: transform 0.3s ease;
        }

        .banner:hover {
            transform: scale(1.01);
        }

        /* Декоративные элементы (облачка) */
        .banner::before,
        .banner::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            pointer-events: none;
        }

        .banner::before {
            width: 300px;
            height: 300px;
            top: -80px;
            right: -80px;
        }

        .banner::after {
            width: 200px;
            height: 200px;
            bottom: -60px;
            left: -60px;
        }

        /* Левая часть – текстовый блок */
        .banner-content {
            position: relative;
            z-index: 2;
            max-width: 60%;
            color: white;
        }

        .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(4px);
            padding: 8px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .banner-content h1 {
            font-size: clamp(2rem, 5vw, 4.2rem);
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 12px;
        }

        .banner-content h1 span {
            background: linear-gradient(to right, #ffffff, #e0e7ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .banner-content .subtitle {
            font-size: clamp(1rem, 1.5vw, 1.5rem);
            font-weight: 400;
            opacity: 0.9;
            margin-bottom: 30px;
            line-height: 1.5;
            max-width: 500px;
        }

        .cta-button {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: white;
            color: #0055FF;
            padding: 16px 40px;
            border-radius: 60px;
            font-weight: 700;
            font-size: 1.1rem;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            border: none;
            cursor: pointer;
        }

        .cta-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
            background: #f0f4ff;
        }

        .cta-button svg {
            width: 22px;
            height: 22px;
            fill: currentColor;
            transition: transform 0.2s ease;
        }

        .cta-button:hover svg {
            transform: translateX(5px);
        }

        /* Правая часть – логотип MAX */
        .banner-logo {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            flex-shrink: 0;
        }

        .banner-logo img {
            width: clamp(100px, 15vw, 200px);
            height: auto;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.2));
            transition: transform 0.3s ease;
        }

        .banner-logo img:hover {
            transform: scale(1.05) rotate(-2deg);
        }

        .banner-logo .logo-text {
            color: rgba(255, 255, 255, 0.8);
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 10px;
            text-align: right;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: 10px;
        }

        /* Адаптив для маленьких экранов */
        @media (max-width: 768px) {
            .banner {
                flex-direction: column;
                justify-content: center;
                align-items: center;
                text-align: center;
                padding: 30px 25px;
                aspect-ratio: auto;
                min-height: 500px;
                border-radius: 24px;
            }

            .banner-content {
                max-width: 100%;
                margin-bottom: 30px;
            }

            .banner-content .subtitle {
                max-width: 100%;
            }

            .banner-logo {
                align-items: center;
            }

            .banner-logo .logo-text {
                text-align: center;
            }

            .cta-button {
                padding: 14px 30px;
                font-size: 1rem;
            }
        }

        @media (max-width: 480px) {
            .banner {
                padding: 20px 15px;
                min-height: 420px;
                border-radius: 16px;
            }

            .badge {
                font-size: 0.7rem;
                padding: 6px 14px;
            }

            .banner-content h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>

    <div class="banner">

        <!-- Текстовая часть -->
        <div class="banner-content">
            <div class="badge">🌟 Новое</div>
            <h1>
                StreamLive<br>
                <span>теперь в MAX</span>
            </h1>
            <p class="subtitle">
                Присоединяйтесь к нашему каналу в мессенджере MAX — общайтесь, смотрите стримы и будьте в курсе событий!
            </p>
            <a href="https://max.ru/channel_StreamLive" class="cta-button">
                Перейти в канал
                <svg viewBox="0 0 24 24" width="24" height="24">
                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                </svg>
            </a>
        </div>

        <!-- Правая часть – логотип MAX -->
        <div class="banner-logo">
            <!-- Используем официальный логотип MAX с сайта -->
            <img src="https://max.ru/s/img/big-logo.png" alt="Логотип MAX" loading="lazy">
            <div class="logo-text">Мессенджер MAX</div>
        </div>

    </div>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>

        .banner {
            max-width: 1100px;
            width: 100%;
            background: linear-gradient(145deg, #0088cc, #005f8a);
            border-radius: 32px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 45px 55px;
            box-shadow: 0 25px 50px rgba(0, 136, 204, 0.4);
            transition: transform 0.25s ease;
            position: relative;
        }

        .banner:hover {
            transform: scale(1.01);
        }

        /* Декоративный фон */
        .banner::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.07) 0%, transparent 70%);
            top: -120px;
            right: -80px;
            border-radius: 50%;
            pointer-events: none;
        }

        .banner::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
            bottom: -100px;
            left: -60px;
            border-radius: 50%;
            pointer-events: none;
        }

        /* Левая часть */
        .banner-content {
            position: relative;
            z-index: 2;
            color: white;
            max-width: 58%;
        }

        .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(4px);
            padding: 6px 18px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border: 1px solid rgba(255, 255, 255, 0.15);
            margin-bottom: 16px;
        }

        .banner-content h1 {
            font-size: clamp(2rem, 4.5vw, 3.8rem);
            font-weight: 900;
            line-height: 1.15;
            margin-bottom: 10px;
        }

        .banner-content h1 .highlight {
            background: linear-gradient(to right, #ffffff, #d4edff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .banner-content .subtitle {
            font-size: clamp(0.95rem, 1.4vw, 1.3rem);
            opacity: 0.92;
            margin-bottom: 28px;
            line-height: 1.6;
            max-width: 480px;
        }

        .cta-button {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: white;
            color: #0088cc;
            padding: 16px 38px;
            border-radius: 60px;
            font-weight: 700;
            font-size: 1.1rem;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            border: none;
            cursor: pointer;
        }

        .cta-button:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.3);
            background: #f5faff;
        }

        .cta-button svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
            transition: transform 0.2s;
        }

        .cta-button:hover svg {
            transform: translateX(5px);
        }

        /* Правая часть – логотип Telegram + название */
        .banner-logo {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            flex-shrink: 0;
        }

        .banner-logo .tg-icon {
            width: clamp(90px, 13vw, 160px);
            height: auto;
            filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.2));
            transition: transform 0.3s ease;
        }

        .banner-logo .tg-icon:hover {
            transform: scale(1.06) rotate(-3deg);
        }

        .banner-logo .channel-name {
            margin-top: 12px;
            font-weight: 700;
            font-size: 1.2rem;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.15);
            padding: 6px 18px;
            border-radius: 40px;
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .banner-logo .channel-name a {
            color: white;
            text-decoration: none;
        }

        .banner-logo .channel-name a:hover {
            text-decoration: underline;
        }

        /* Адаптив */
        @media (max-width: 768px) {
            .banner {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 30px 25px;
                min-height: 480px;
                border-radius: 24px;
            }

            .banner-content {
                max-width: 100%;
                margin-bottom: 25px;
            }

            .banner-content .subtitle {
                max-width: 100%;
            }

            .banner-logo {
                align-items: center;
            }

            .banner-logo .channel-name {
                font-size: 1rem;
                padding: 5px 14px;
            }
        }

        @media (max-width: 480px) {
            .banner {
                padding: 20px 15px;
                min-height: 400px;
                border-radius: 16px;
            }

            .badge {
                font-size: 0.7rem;
                padding: 4px 12px;
            }

            .banner-content h1 {
                font-size: 1.8rem;
            }

            .cta-button {
                padding: 12px 24px;
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>

    <div class="banner">

        <!-- Текстовый блок -->
        <div class="banner-content">
            <div class="badge">НОВОЕ</div>
            <h1>
                <span class="highlight">StreamLive</span><br>
                в Telegram
            </h1>
            <p class="subtitle">
                Подписывайтесь на наш канал — эксклюзивные стримы, новости и общение с сообществом. Будьте всегда на связи!
            </p>
            <a href="https://t.me/streamliveru" target="_blank" class="cta-button">
                Подписаться
                <svg viewBox="0 0 24 24" width="24" height="24">
                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                </svg>
            </a>
        </div>

        <!-- Логотип Telegram и название канала -->
        <div class="banner-logo">
            <!-- Иконка Telegram (SVG) -->
            <svg class="tg-icon" viewBox="0 0 240 240" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="240" height="240" rx="60" fill="white"/>
                <path d="M180.5 80.5L163.5 165.5C162.5 170.5 159.5 172 155 169.5L118.5 143.5L101 160.5C99.5 162 98 163.5 95.5 163.5L97.5 126.5L151.5 78.5C153.5 76.5 152 75 149.5 77L82.5 120.5L46.5 109.5C41.5 108 41 104.5 47 102L175.5 68C180.5 66.5 184.5 70 180.5 80.5Z" fill="#0088cc"/>
            </svg>
            <div class="channel-name">
                <a href="https://t.me/streamliveru" target="_blank">@streamliveru</a>
            </div>
        </div>

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
<!-- Таймер сезонов (исправленный) -->
<div id="season-timer" style="text-align:center;background:var(--card);padding:12px;margin:10px 0;border-radius:8px;font-size:15px;color:var(--accent-2);border:1px solid var(--border);box-shadow:0 2px 10px rgba(0,0,0,0.3);">
  <strong id="season-label">До лета</strong>: <span id="countdown" style="font-weight:bold;color:#ffeb3b;">загрузка...</span>
</div>

<script>
function getSeasonTarget() {
  const now = new Date();
  const y = now.getFullYear();
  const summer = new Date(y, 5, 1);
  const winter = new Date(y, 11, 1);
  const ny = new Date(y + 1, 0, 1);

  if (now < summer) {
    document.getElementById('season-label').innerHTML = '🌞 До лета';
    return summer;
  } else if (now < winter) {
    document.getElementById('season-label').innerHTML = '❄️ До зимы';
    return winter;
  } else {
    document.getElementById('season-label').innerHTML = '🎄 До Нового года';
    return ny;
  }
}

function updateCountdown() {
  const target = getSeasonTarget();
  const diff = target - new Date();
  if (diff <= 0) {
    document.getElementById('countdown').innerHTML = 'Сейчас!';
    return;
  }
  const days = Math.floor(diff / (1000*60*60*24));
  const hours = Math.floor((diff % (1000*60*60*24)) / (1000*60*60));
  const mins = Math.floor((diff % (1000*60*60)) / (1000*60));
  document.getElementById('countdown').innerHTML = `${days}д ${hours}ч ${mins}м`;
}

setInterval(updateCountdown, 30000);
updateCountdown();
</script>

<style>
.snowflake, .summer-effect { position:fixed; top:-20px; z-index:9999; pointer-events:none; animation:fall linear forwards; font-size:18px; }
@keyframes fall { to { transform: translateY(110vh) rotate(360deg); } }
</style>
<?php endif; ?>