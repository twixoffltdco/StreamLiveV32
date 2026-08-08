<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$u = current_user();
header('Content-Type: text/plain; charset=utf-8');
if (!$u || (($u['role'] ?? '') !== 'admin' && empty($u['is_admin']))) {
  // allow once without admin for emergency on free host
  echo "Запусти под админом желательно.\n";
}
$pdo = db();
$file = __DIR__ . '/sql/migrations/051_premiere_forum_plugins_export.sql';
$sql = is_file($file) ? file_get_contents($file) : '';
$n = 0;
foreach (preg_split('/;\s*\n/', $sql) as $q) {
  $q = trim($q);
  if ($q === '' || strpos($q, '--') === 0) continue;
  try { $pdo->exec($q); $n++; echo "OK: " . substr($q, 0, 60) . "…\n"; }
  catch (Throwable $e) { echo "skip: " . $e->getMessage() . "\n"; }
}
if (is_file(__DIR__ . '/includes/forum_plugins.php')) {
  require_once __DIR__ . '/includes/forum_plugins.php';
  forum_plugins_ensure();
  echo "forum_plugins_ensure OK\n";
}
echo "Done statements attempted: $n\n";
