<?php
// Та же самая правка, что и в diagnose_network.php — не подключаем includes/header.php,
// он печатает целую HTML-страницу. Нужны только функции из auth.php.
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();

header('Content-Type: text/plain; charset=utf-8'); // теперь первый вывод — сработает

echo "=== Диагностика панелей модерации ===\n\n";
echo "Пользователь: {$__user['username']} (id={$__user['id']})\n";
echo "Роль (users.role): {$__user['role']}\n\n";

echo "-- Панель /moderator/index.php и /moderator/flagged.php (модерация каналов ТВ/радио) --\n";
echo in_array($__user['role'], ['moderator', 'admin'], true)
  ? "ДОСТУП ЕСТЬ (роль moderator или admin)\n"
  : "ДОСТУПА НЕТ. Чтобы дать — /admin/users.php → выбрать этому пользователю роль 'moderator'\n";

echo "\n-- Панель /moderator/forum.php (восстановление удалённых тем/постов форума) --\n";
$isForumMod = function_exists('is_forum_moderator') ? is_forum_moderator($__user) : null;
if ($isForumMod === null) {
  echo "ОШИБКА: функция is_forum_moderator() не найдена — проверьте includes/auth.php\n";
} else {
  echo $isForumMod
    ? "ДОСТУП ЕСТЬ\n"
    : "ДОСТУПА НЕТ. Это отдельная система от users.role! Назначить — /admin/forum.php → блок «Модераторы форума»\n";
}

echo "\n-- Проверка таблиц в БД --\n";
foreach (['github_connections', 'deployed_services', 'videos', 'video_comments', 'video_likes', 'video_favorites', 'forum_moderators'] as $t) {
  try {
    db()->query("SELECT 1 FROM {$t} LIMIT 1");
    echo "{$t}: OK\n";
  } catch (\Throwable $e) {
    echo "{$t}: НЕТ ТАБЛИЦЫ — не залита миграция ({$e->getMessage()})\n";
  }
}

echo "\n-- Проверка колонок геймификации в users --\n";
try {
  $row = db()->query("SELECT xp, total_active_days, cycle_number, last_active_date FROM users WHERE id = {$__user['id']}")->fetch();
  echo "xp={$row['xp']}, total_active_days={$row['total_active_days']}, cycle={$row['cycle_number']}, last_active_date=" . ($row['last_active_date'] ?? 'NULL') . "\n";
} catch (\Throwable $e) {
  echo "ОШИБКА: {$e->getMessage()} — не залита миграция геймификации\n";
}

echo "\n-- Проверка GitHub-подключения --\n";
try {
  $gh = db()->prepare('SELECT github_username, connected_at FROM github_connections WHERE user_id = ?');
  $gh->execute([$__user['id']]);
  $row = $gh->fetch();
  echo $row ? "Подключён: @{$row['github_username']}\n" : "GitHub не подключён (это нормально, если ещё не заходил на /github_connect.php)\n";
} catch (\Throwable $e) {
  echo "ОШИБКА: {$e->getMessage()} — не залита миграция 018_github_deploy.sql, поэтому /github_connect.php и /github_deploy.php не могут работать\n";
}

echo "\nЭту страницу можно удалить после отладки.\n";
