<?php
/**
 * Восстановление префиксов после переноса БД:
 * - все общие (не personal) → is_system=1, is_active=1
 * - title = название канала → is_system=0 (не трогаем is_active)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_display.php';
user_display_ensure_schema();

$pdo = db();
echo "=== prefix restore ===\n";

try {
  $n = $pdo->exec(
    "UPDATE user_prefixes SET is_system=1, is_active=1
     WHERE COALESCE(is_personal,0)=0
       AND (owner_user_id IS NULL OR owner_user_id=0)"
  );
  echo "marked_system_active=" . (int)$n . "\n";
} catch (Throwable $e) {
  echo "mark error: " . $e->getMessage() . "\n";
}

$ch = [];
try {
  foreach ($pdo->query('SELECT title FROM channels')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ct) {
    $k = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$ct)) : strtolower(trim((string)$ct));
    if ($k !== '') $ch[$k] = true;
  }
} catch (Throwable $e) {}

$stripped = 0;
try {
  foreach ($pdo->query('SELECT id, title FROM user_prefixes')->fetchAll() ?: [] as $r) {
    $tk = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($r['title'] ?? ''))) : strtolower(trim((string)($r['title'] ?? '')));
    if ($tk !== '' && !empty($ch[$tk])) {
      $pdo->prepare('UPDATE user_prefixes SET is_system=0 WHERE id=?')->execute([(int)$r['id']]);
      $stripped++;
    }
  }
} catch (Throwable $e) {
  echo "strip: " . $e->getMessage() . "\n";
}
echo "channel_title_stripped_system=$stripped\n";

try {
  $all = (int)$pdo->query('SELECT COUNT(*) FROM user_prefixes')->fetchColumn();
  $sys = (int)$pdo->query('SELECT COUNT(*) FROM user_prefixes WHERE COALESCE(is_system,0)=1')->fetchColumn();
  $act = (int)$pdo->query('SELECT COUNT(*) FROM user_prefixes WHERE is_active=1 AND COALESCE(is_system,0)=1')->fetchColumn();
  echo "total=$all system=$sys system_active=$act\n";
  foreach ($pdo->query('SELECT id, title, is_system, is_active, is_personal FROM user_prefixes ORDER BY id ASC LIMIT 30')->fetchAll() as $r) {
    echo sprintf("#%d %s sys=%s act=%s pers=%s\n", $r['id'], $r['title'], $r['is_system'], $r['is_active'], $r['is_personal'] ?? 0);
  }
} catch (Throwable $e) {
  echo $e->getMessage() . "\n";
}
echo "DONE — открой /admin/prefixes и /moderator/prefixes\n";
