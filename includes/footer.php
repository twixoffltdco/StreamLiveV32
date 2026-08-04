<?php require_once __DIR__ . '/mini_player.php'; ?>

<?php
// Определяем активную вкладку нижней навигации по текущему файлу — не нужно
// прописывать это вручную на каждой странице сайта.
$__scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__cur = in_array($__scriptName, ['index.php', 'catalog.php', 'channel.php', 'channel-pc.php', 'channel-full.php', 'shorts.php', 'smotrim.php']) ? 'catalog'
  : (strpos($__scriptName, 'forum') === 0 ? 'forum'
  : ((in_array($__scriptName, ['messages.php', 'broadcast_channels.php', 'broadcast_channel.php'])) ? 'messages'
  : ((in_array($__scriptName, ['dashboard.php', 'new_channel.php', 'channel_manage.php', 'profile.php'])) ? 'profile' : '')));
?>

<nav class="mobile-tabbar">
  <a href="/catalog" class="mobile-tab <?= $__cur === 'catalog' ? 'active' : '' ?>">
    <svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 3l9 8h-3v9h-5v-6H11v6H6v-9H3z"/></svg>
    <span>Главная</span>
  </a>
  <a href="/forum" class="mobile-tab <?= $__cur === 'forum' ? 'active' : '' ?>">
    <svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <span>Форум</span>
  </a>
  <a href="<?= $__user ? '/new_channel.php' : '/auth/login.php' ?>" class="mobile-tab mobile-tab-plus">
    <span class="mobile-tab-plus-icon">+</span>
  </a>
  <a href="/messages" class="mobile-tab <?= $__cur === 'messages' ? 'active' : '' ?>">
    <svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M2 4h20v14H6l-4 4z"/></svg>
    <span>Сообщения</span>
    <?php if (!empty($__unreadCount)): ?><span class="mobile-tab-badge"><?= $__unreadCount > 9 ? '9+' : $__unreadCount ?></span><?php endif; ?>
  </a>
  <a href="<?= $__user ? '/profile.php?username=' . urlencode($__user['username']) : '/auth/login.php' ?>" class="mobile-tab <?= $__cur === 'profile' ? 'active' : '' ?>">
    <?php if ($__user && !empty(user_avatar_url($__user, 48))): ?>
      <img src="<?= e(user_avatar_url($__user, 48)) ?>" alt="" class="mobile-tab-avatar">
    <?php else: ?>
      <svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5z"/></svg>
    <?php endif; ?>
    <span>Профиль</span>
  </a>
</nav>

<footer class="site-footer">
  <div class="container site-footer-inner">
    <span>© <?= date('Y') ?> <?= e(SITE_NAME) ?> · сделано с <span style="color:#e53935">❤</span> — <a href="https://oinktechltd.tatnet.app" target="_blank" rel="noopener" style="color:inherit">ТОО OinkTech Ltd Co and Twixoff</a></span>
    <nav class="site-footer-links">
      <a href="/docs">Документация</a>
      <a href="/legal/terms">Условия использования</a>
      <a href="/legal/privacy">Конфиденциальность</a>
      <a href="/legal/cookies">Cookie</a>
      <a href="/smotrim">Smotrim</a>
      <a href="/streamtok">StreamTok</a>
      <a href="/platforma/">Платформа</a>
      <a href="https://t.me/streamliveru" target="_blank" rel="noopener">Telegram</a>
      <a href="https://max.ru/channel_StreamLive" target="_blank" rel="noopener">MAX</a>
      <a href="https://vk.com/streamlivetv" target="_blank" rel="noopener">ВК</a>
      <a href="https://vk.com/tvstreamlivetv" target="_blank" rel="noopener">ВК зеркало</a>
      <a href="https://vk.com/tvstreamlive" target="_blank" rel="noopener">ВК зеркало 2</a>
    </nav>
  </div>
</footer>
<div id="cookie-consent" class="cookie-banner" style="display:none">
  <div class="cookie-banner-text">
    Мы используем cookie для работы входа в аккаунт и показа рекламы. Продолжая пользоваться сайтом, вы соглашаетесь с
    <a href="/legal/cookies">использованием cookie</a> и <a href="/legal/privacy">политикой конфиденциальности</a>.
  </div>
  <div class="cookie-banner-actions">
    <button type="button" class="btn btn-primary btn-sm" id="cookie-consent-accept">Принять</button>
  </div>
</div>
<script>
(function () {
  var KEY = 'streamlive_cookie_consent';
  try {
    if (localStorage.getItem(KEY) === '1') return;
  } catch (e) { return; /* localStorage недоступен — не показываем баннер, чтобы не спамить каждый раз */ }
  var banner = document.getElementById('cookie-consent');
  if (!banner) return;
  banner.style.display = 'flex';
  var btn = document.getElementById('cookie-consent-accept');
  btn.addEventListener('click', function () {
    try { localStorage.setItem(KEY, '1'); } catch (e) {}
    banner.style.display = 'none';
  });
})();
</script>
<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.bb-code-copy');
  if (!btn) return;
  var target = document.getElementById(btn.dataset.target);
  if (!target) return;
  navigator.clipboard.writeText(target.innerText).then(function () {
    var old = btn.textContent;
    btn.textContent = 'Скопировано!';
    setTimeout(function () { btn.textContent = old; }, 1500);
  }).catch(function () { /* буфер обмена недоступен (например, не HTTPS) */ });
});
</script>
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
<?php if (function_exists('themes_active')) { $__activeTheme = themes_active(); if (!empty($__activeTheme['footer'])) theme_safe_include(themes_dir() . '/' . $__activeTheme['footer']); } ?>
<script src="/assets/js/push-notify.js?v=6" defer></script>
</body>
</html>
