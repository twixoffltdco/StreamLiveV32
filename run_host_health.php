<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/functions.php';
echo "php=" . PHP_VERSION . "\n";
try {
  $pdo = db();
  echo "db=ok\n";
  foreach (['users','forum_threads','forum_posts','forum_post_likes','videos','channels'] as $t) {
    try {
      $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
      echo "$t=$n\n";
    } catch (Throwable $e) {
      echo "$t=MISSING " . $e->getMessage() . "\n";
    }
  }
} catch (Throwable $e) {
  echo "db_fail=" . $e->getMessage() . "\n";
}
echo "DONE\n";
