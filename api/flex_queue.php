<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/flex_world.php';
$u = current_user();
if (!$u) { echo json_encode(['ok'=>false]); exit; }
flex_world_ensure();
$uid = (int)$u['id'];
$pos = 0; $total = 0;
try {
  $total = (int)db()->query('SELECT COUNT(*) FROM flex_world_queue')->fetchColumn();
  $st = db()->prepare('SELECT 1 FROM flex_world_queue WHERE user_id=?');
  $st->execute([$uid]);
  if ($st->fetchColumn()) $pos = flex_world_queue_pos($uid);
} catch (Throwable $e) {}
$servers = flex_world_servers();
$free = 0;
foreach ($servers as $s) if (!$s['full']) $free++;
echo json_encode(['ok'=>true,'pos'=>$pos,'total'=>$total,'free_servers'=>$free], JSON_UNESCAPED_UNICODE);
