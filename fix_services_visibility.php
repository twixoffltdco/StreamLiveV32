<?php
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();
header('Content-Type: text/plain; charset=utf-8');

$stmt = db()->prepare("UPDATE deployed_services SET is_public = 1 WHERE user_id = ? AND (is_public = 0 OR is_public IS NULL)");
$stmt->execute([$__user['id']]);
echo "Обновлено сервисов: " . $stmt->rowCount() . "\n";
echo "Теперь зайди на /services.php — твои сервисы должны появиться в списке.\n";
echo "Этот файл (fix_services_visibility.php) можно удалить с хостинга после запуска.\n";
