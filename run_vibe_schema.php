<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/vibe.php';
vibe_ensure_schema();
$f = vibe_active_frame();
echo "OK vibe schema\n";
echo "active_frame=" . ($f ? ($f['code'] . ' ' . $f['title']) : 'none') . "\n";
echo "server_msk_approx check Europe/Moscow\n";
