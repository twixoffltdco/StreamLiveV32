<?php
require_once __DIR__ . '/includes/db.php';
if (!defined('INSTALLED') || !INSTALLED) { header('Location: /install/index.php'); exit; }
header('Location: /catalog.php');
exit;
