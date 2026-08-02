<?php
/**
 * Healthcheck для uptime-мониторов (UptimeRobot и т.п.)
 * Не светит секреты.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ok = true;
$checks = [];

$checks['php'] = PHP_VERSION;
try {
  $cfg = __DIR__ . '/config/config.php';
  $checks['config'] = is_file($cfg);
  if (!$checks['config']) $ok = false;
} catch (Throwable $e) {
  $checks['config'] = false;
  $ok = false;
}

try {
  if (is_file(__DIR__ . '/includes/db.php')) {
    require_once __DIR__ . '/includes/db.php';
    $pdo = db();
    $pdo->query('SELECT 1');
    $checks['db'] = true;
  } else {
    $checks['db'] = false;
    $ok = false;
  }
} catch (Throwable $e) {
  $checks['db'] = false;
  $ok = false;
}

$checks['maintenance'] = is_file(__DIR__ . '/storage/maintenance.on');
if ($checks['maintenance']) $ok = false;

http_response_code($ok ? 200 : 503);
echo json_encode(['ok' => $ok, 'checks' => $checks, 'ts' => gmdate('c')], JSON_UNESCAPED_UNICODE);
