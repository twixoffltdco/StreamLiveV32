<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
$__user = current_user();
$pageTitle = 'Использование cookie';
$seoDescription = 'Как ' . SITE_NAME . ' использует файлы cookie';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="legal-doc">
    <h1>Использование cookie</h1>
    <p class="legal-updated">Последнее обновление: <?= date('d.m.Y') ?></p>

    <p>Мы используем файлы cookie, чтобы сайт работал корректно и чтобы вам не приходилось входить в аккаунт заново на каждой странице.</p>

    <h2>Какие cookie мы используем</h2>
    <table class="admin-table" style="margin-top:12px">
      <thead>
        <tr><th>Cookie</th><th>Назначение</th><th>Срок жизни</th></tr>
      </thead>
      <tbody>
        <tr><td>PHPSESSID (сессия)</td><td>Обязательный. Хранит вход в аккаунт, состояние 2FA, CSRF-защиту форм.</td><td>До закрытия браузера / выхода</td></tr>
        <tr><td>streamlive_cookie_consent</td><td>Хранит ваш выбор в баннере согласия на cookie (localStorage, не серверный cookie).</td><td>Пока не очистите данные браузера</td></tr>
        <tr><td>Cookie рекламной сети</td><td>Устанавливаются сторонним рекламным скриптом (ad-network.tatnet.app) для показа рекламы. Мы не контролируем их напрямую.</td><td>По правилам рекламной сети</td></tr>
      </tbody>
    </table>

    <h2>Обязательные и необязательные</h2>
    <p>Cookie сессии (вход в аккаунт, CSRF-защита) — технически необходимы для работы сайта и не отключаются, иначе вход и формы просто не будут работать. Рекламные cookie можно заблокировать в настройках браузера — это не помешает пользоваться остальным функционалом Платформы.</p>

    <h2>Как отключить cookie</h2>
    <p>Вы можете в любой момент удалить или заблокировать cookie в настройках своего браузера. Учтите, что после этого вход в аккаунт может не сохраняться между посещениями.</p>

    <p class="legal-links"><a href="/legal/terms.php">Условия использования</a> · <a href="/legal/privacy.php">Политика конфиденциальности</a> · <a href="/docs.php">Документация</a></p>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
