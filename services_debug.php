<?php
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();
header('Content-Type: text/plain; charset=utf-8');

echo "=== Диагностика лимита деплоя / автостопа / модерации сервисов ===\n\n";

echo "-- Пользователь --\n";
echo "id={$__user['id']}, username={$__user['username']}, is_verified=" . var_export((bool)($__user['is_verified'] ?? false), true) . "\n\n";

echo "-- Колонка suspended в deployed_services --\n";
try {
  $cols = db()->query("SHOW COLUMNS FROM deployed_services")->fetchAll();
  $names = array_column($cols, 'Field');
  echo in_array('suspended', $names, true) ? "suspended: есть\n" : "suspended: ОТСУТСТВУЕТ — миграция 027 не залита, лимит и автостоп физически не могут работать без этой колонки\n";
  echo in_array('suspended_reason', $names, true) ? "suspended_reason: есть\n" : "suspended_reason: ОТСУТСТВУЕТ\n";
} catch (\Throwable $e) {
  echo "ОШИБКА: " . $e->getMessage() . "\n";
}

echo "\n-- Твои текущие сервисы (все статусы) --\n";
try {
  $stmt = db()->prepare("SELECT id, name, slug, status, suspended, created_at FROM deployed_services WHERE user_id = ?");
  $stmt->execute([$__user['id']]);
  $rows = $stmt->fetchAll();
  if (!$rows) { echo "Ни одного сервиса нет вообще\n"; }
  foreach ($rows as $r) {
    echo "id={$r['id']} name={$r['name']} slug={$r['slug']} status={$r['status']} suspended=" . var_export((bool)$r['suspended'], true) . " created={$r['created_at']}\n";
  }
} catch (\Throwable $e) {
  echo "ОШИБКА: " . $e->getMessage() . "\n";
}

echo "\n-- Расчёт лимита прямо сейчас (та же логика, что в github_deploy.php) --\n";
try {
  $stmt = db()->prepare("SELECT COUNT(*) FROM deployed_services WHERE user_id = ? AND status = 'live' AND suspended = 0");
  $stmt->execute([$__user['id']]);
  $active = (int)$stmt->fetchColumn();
  $max = !empty($__user['is_verified']) ? 'безлимит (галочка есть)' : '1';
  echo "Активных (live, не приостановленных): {$active}\n";
  echo "Максимум для тебя: {$max}\n";
  echo (!$__user['is_verified'] && $active >= 1) ? "СЛЕДУЮЩИЙ ДЕПЛОЙ ДОЛЖЕН БЫТЬ ЗАБЛОКИРОВАН\n" : "Деплой сейчас прошёл бы\n";
} catch (\Throwable $e) {
  echo "ОШИБКА: " . $e->getMessage() . " — если тут ошибка про unknown column suspended, вот и вся причина\n";
}

echo "\n-- Реальное содержимое github_deploy.php на этом хостинге (первые 2000 символов) --\n";
$path = __DIR__ . '/github_deploy.php';
if (file_exists($path)) {
  $content = file_get_contents($path);
  echo "Размер файла: " . strlen($content) . " байт\n";
  echo "Содержит 'activeServicesCount': " . (strpos($content, 'activeServicesCount') !== false ? 'ДА — код лимита реально на хостинге' : 'НЕТ — файл на хостинге СТАРЫЙ, залит не тот github_deploy.php') . "\n";
} else {
  echo "Файл github_deploy.php не найден по пути {$path}\n";
}

echo "\n-- То же самое для s.php (автостоп) --\n";
$path2 = __DIR__ . '/s.php';
if (file_exists($path2)) {
  $content2 = file_get_contents($path2);
  echo "Содержит 'ownerInactive': " . (strpos($content2, 'ownerInactive') !== false ? 'ДА — код автостопа реально на хостинге' : 'НЕТ — s.php на хостинге СТАРЫЙ') . "\n";
} else {
  echo "s.php не найден\n";
}

echo "\n-- Модератор: файл moderator/services.php --\n";
$path3 = __DIR__ . '/moderator/services.php';
echo file_exists($path3) ? "Файл существует на диске: OK\n" : "Файла НЕТ на диске — не залит\n";
