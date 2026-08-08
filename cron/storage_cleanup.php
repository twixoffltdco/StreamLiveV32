<?php
/**
 * Очистка storage:
 * - кэш и временные файлы старше 5 часов
 * - задеплоенные сервисы: если владелец не заходил 1 месяц + 1 неделя (~37 суток)
 *
 * Можно вызывать по cron или из web (с файловым lock).
 */
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';

$lock = sys_get_temp_dir() . '/sl_storage_cleanup.lock';
$fp = @fopen($lock, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
  if (PHP_SAPI === 'cli') echo "locked\n";
  exit;
}

$log = [];
$now = time();
$fiveHours = 5 * 3600;
$serviceTtl = (30 + 7) * 86400; // 1 месяц + 1 неделя (~37 суток)

function sl_rm_tree(string $dir): int {
  $n = 0;
  if (!is_dir($dir)) return 0;
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($it as $f) {
    if ($f->isDir()) @rmdir($f->getPathname());
    else { @unlink($f->getPathname()); $n++; }
  }
  @rmdir($dir);
  return $n;
}

// 1) storage/cache
$cacheDir = $root . '/storage/cache';
if (is_dir($cacheDir)) {
  $deleted = 0;
  foreach (glob($cacheDir . '/*') ?: [] as $f) {
    if (!is_file($f) && !is_dir($f)) continue;
    $mtime = @filemtime($f) ?: 0;
    if ($mtime > 0 && ($now - $mtime) > $fiveHours) {
      if (is_dir($f)) $deleted += sl_rm_tree($f);
      else { @unlink($f); $deleted++; }
    }
  }
  $log[] = "cache cleaned files~$deleted";
}

// 2) tmp poll/geo files
foreach (glob(sys_get_temp_dir() . '/sl_*') ?: [] as $f) {
  if (is_file($f) && ($now - (filemtime($f) ?: 0)) > $fiveHours) @unlink($f);
}

// 3) deployed services inactive users
try {
  $pdo = db();
  $st = $pdo->query("SELECT ds.id, ds.slug, ds.user_id, u.last_seen_at
    FROM deployed_services ds
    LEFT JOIN users u ON u.id = ds.user_id
    WHERE ds.status = 'live' OR ds.status IS NULL OR ds.status = 'active'");
  $rows = $st ? $st->fetchAll() : [];
  foreach ($rows as $r) {
    $seen = $r['last_seen_at'] ? strtotime($r['last_seen_at']) : 0;
    // если last_seen нет — смотрим deployed_at / не трогаем свежие
    if ($seen <= 0) continue;
    if (($now - $seen) < $serviceTtl) continue;
    $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$r['slug']);
    if ($slug === '') continue;
    $dir = $root . '/services_data/' . $slug;
    if (is_dir($dir)) {
      $n = sl_rm_tree($dir);
      $log[] = "service removed {$slug} files~$n";
    }
    try {
      $pdo->prepare("UPDATE deployed_services SET status = 'purged' WHERE id = ?")->execute([(int)$r['id']]);
    } catch (Throwable $e) {
      try { $pdo->prepare("DELETE FROM deployed_services WHERE id = ?")->execute([(int)$r['id']]); } catch (Throwable $e2) {}
    }
  }
} catch (Throwable $e) {
  $log[] = 'services skip: ' . $e->getMessage();
}

// marker
@file_put_contents($root . '/storage/cache/last_cleanup.txt', date('c') . "\n" . implode("\n", $log));

flock($fp, LOCK_UN);
fclose($fp);

if (PHP_SAPI === 'cli' || isset($_GET['debug'])) {
  header('Content-Type: text/plain; charset=utf-8');
  echo implode("\n", $log) ?: "ok nothing";
}
