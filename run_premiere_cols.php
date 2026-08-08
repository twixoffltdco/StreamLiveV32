<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/premiere_helpers.php';
premiere_ensure_columns();
echo "columns ok\n";
echo "server_now=" . date('Y-m-d H:i:s') . " tz=" . date_default_timezone_get() . "\n";
try {
  $rows = db()->query(
    "SELECT id, slug, title, is_premiere, premiere_at, premiere_end_at, premiere_used, status
     FROM videos ORDER BY id DESC LIMIT 15"
  )->fetchAll();
  foreach ($rows as $r) {
    echo sprintf(
      "#%d %s is_prem=%s at=%s end=%s used=%s status=%s\n",
      (int)$r['id'],
      $r['slug'] ?? '',
      $r['is_premiere'] ?? '?',
      $r['premiere_at'] ?? 'null',
      $r['premiere_end_at'] ?? 'null',
      $r['premiere_used'] ?? '?',
      $r['status'] ?? ''
    );
  }
} catch (Throwable $e) {
  echo "list error: " . $e->getMessage() . "\n";
}
echo "DONE — назначь премьеру заново в Студии → Импорт / Расписание\n";
