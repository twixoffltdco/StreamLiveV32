<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

$user = current_user();
if (!$user || empty($user['id'])) {
  echo json_encode(['ok' => true, 'logged' => false, 'subs' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

$uid = (int)$user['id'];
$subs = [];
$queries = [
  "SELECT c.id, c.title, c.slug, c.logo_url AS avatar
   FROM favorites f INNER JOIN channels c ON c.id = f.channel_id
   WHERE f.user_id = ? LIMIT 24",
  "SELECT c.id, c.title, c.slug, c.logo_url AS avatar
   FROM channel_favorites f INNER JOIN channels c ON c.id = f.channel_id
   WHERE f.user_id = ? LIMIT 24",
  "SELECT c.id, c.title, c.slug, '' AS avatar
   FROM favorites f INNER JOIN channels c ON c.id = f.channel_id
   WHERE f.user_id = ? LIMIT 24",
];
foreach ($queries as $sql) {
  try {
    $st = db()->prepare($sql);
    $st->execute([$uid]);
    $subs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($subs) break;
  } catch (Throwable $e) {}
}

echo json_encode([
  'ok' => true,
  'logged' => true,
  'id' => $uid,
  'subs' => $subs,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
