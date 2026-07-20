<?php
require_once __DIR__ . '/includes/auth.php';
$__user = require_login();
header('Content-Type: application/json; charset=utf-8');

$stmt = db()->prepare(
  "SELECT DISTINCT sp.id, sp.title FROM sticker_packs sp
   LEFT JOIN sticker_pack_subscriptions sub ON sub.pack_id = sp.id AND sub.user_id = ?
   WHERE sp.owner_id = ? OR sub.user_id IS NOT NULL
   ORDER BY sp.title"
);
$stmt->execute([$__user['id'], $__user['id']]);
$packs = $stmt->fetchAll();

$result = [];
foreach ($packs as $pack) {
  $itemsStmt = db()->prepare('SELECT code, image_url FROM sticker_pack_items WHERE pack_id = ? ORDER BY id');
  $itemsStmt->execute([$pack['id']]);
  $items = $itemsStmt->fetchAll();
  if ($items) {
    $result[] = ['title' => $pack['title'], 'items' => $items];
  }
}

echo json_encode(['ok' => true, 'packs' => $result]);
