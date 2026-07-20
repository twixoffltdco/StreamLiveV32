<?php
require_once __DIR__ . '/../includes/header.php';
require_admin();
?>
<div class="admin-shell">
  <div class="admin-sidebar">
    <a href="/admin/index.php">Обзор</a>
    <a href="/admin/moderation.php">Модерация</a>
    <a href="/admin/security.php">Безопасность (2FA)</a>
    <a href="/admin/channels.php">Все каналы</a>
    <a href="/admin/forum.php">Форум (категории)</a>
    <a href="/admin/rss.php">RSS-источники</a>
    <a href="/admin/ai.php">ИИ T2000</a>
    <a href="/admin/sources.php">Источники</a>
    <a href="/admin/oauth.php">Соц. авторизация</a>
    <a href="/admin/themes.php">Themes</a>
    <a href="/admin/users.php">Пользователи</a>
    <a href="/admin/update.php">Обновление БД</a>
      <a href="/admin/import.php">Импорт</a>
    <a href="/resources">Ресурсы</a>
  </div>
  <div class="admin-content">
