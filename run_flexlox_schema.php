<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/flexlox.php';
flexlox_ensure_schema();
echo "OK Flexlox walk+merch+NPC\n";
echo "Avatar merch_url column ready\n";
echo "Play: /flexlox/quick.php\n";
