<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/forum_autoclose.php';
header('Content-Type: text/plain; charset=utf-8');
echo 'closed=' . forum_autoclose_run(200) . "\n";
