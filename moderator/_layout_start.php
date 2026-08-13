<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/moderator_auth.php';
require_moderator();
?>
<div class="admin-shell">
  <div class="admin-sidebar">
    <a href="/moderator/index">Очередь на модерацию</a>
    <a href="/moderator/mod_requests">Запросы на подтверждение</a>
    <a href="/moderator/flagged">Скрыть/восстановить канал</a>
    <a href="/moderator/videos">Модерация видео</a>
    <a href="/content_moderation_panel.php">Модерация контента</a>
    <a href="/moderator/users">Пользователи</a>
      <a href="/moderator/resources">Ресурсы</a>
    <a href="/moderator/verification_requests.php">Верификация</a>
    <a href="/moderator/paid_channels.php">Платный контент</a>
</div>
  <div class="admin-content">
