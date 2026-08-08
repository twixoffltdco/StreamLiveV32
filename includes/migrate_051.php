<?php
/** Подключи один раз из install или открой /run_migration_051.php */
function migrate_051_premiere_forum(): void {
  $pdo = db();
  $sql = file_get_contents(dirname(__DIR__) . '/sql/migrations/051_premiere_forum_plugins_export.sql');
  if (!$sql) return;
  foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
    if ($q === '' || strpos($q, '--') === 0) continue;
    try { $pdo->exec($q); } catch (Throwable $e) {}
  }
}
