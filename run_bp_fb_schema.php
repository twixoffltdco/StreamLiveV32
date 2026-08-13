<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/battle_pass.php';
require_once __DIR__ . '/includes/flexblocks.php';
require_once __DIR__ . '/includes/paid_access.php';
bp_ensure_schema();
fb_ensure_schema();
paid_ensure_schema();
echo "OK battle_pass + flexblocks + paid(3 days)\n";
echo "Admin: /admin/battle_pass.php\n";
echo "User: /battle_pass.php\n";
echo "Game: /flexblocks/\n";
