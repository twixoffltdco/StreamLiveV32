<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (is_file(__DIR__ . '/../includes/user_display.php')) {
  try { require_once __DIR__ . '/../includes/user_display.php'; } catch (Throwable $e) {}
}
$__user = current_user();
if (!$__user || !in_array(($__user['role'] ?? ''), ['admin','moderator'], true)) {
  http_response_code(403);
  echo 'Нет доступа';
  exit;
}
try { if (function_exists('user_display_ensure_schema')) user_display_ensure_schema(); } catch (Throwable $e) {}
try { db()->exec('ALTER TABLE user_prefixes ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}

$rows = [];
try {
  $rows = db()->query('SELECT * FROM user_prefixes WHERE is_system=1 OR is_system IS NULL ORDER BY id DESC LIMIT 100')->fetchAll() ?: [];
} catch (Throwable $e) {
  try { $rows = db()->query('SELECT * FROM user_prefixes ORDER BY id DESC LIMIT 100')->fetchAll() ?: []; } catch (Throwable $e2) {}
}

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Префиксы</h2>
<table class="admin-table" style="width:100%">
  <tr><th>ID</th><th>Название</th><th>system</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><?= htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= (int)($r['is_system'] ?? 0) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<p style="opacity:.7">Создание системных префиксов — в админке.</p>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
