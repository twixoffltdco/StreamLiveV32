<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/content_moderation.php';
header('Content-Type: text/plain; charset=utf-8');
cmod_ensure_schema();
echo "OK\n";
