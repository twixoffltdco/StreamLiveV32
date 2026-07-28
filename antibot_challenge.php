<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/antibot.php';

antibot_ensure_table();
$ip = antibot_client_ip();
$returnTo = $_GET['return_to'] ?? '/';
if ($returnTo === '' || $returnTo[0] !== '/' || substr($returnTo, 0, 2) === '//') $returnTo = '/';

$stmt = db()->prepare('SELECT captcha_pass_until FROM antibot_ip_log WHERE ip = ?');
$stmt->execute([$ip]);
$row = $stmt->fetch();
if (antibot_captcha_pass_valid($row)) {
  header('Location: ' . $returnTo);
  exit;
}

antibot_new_code($ip);
require __DIR__ . '/antibot_challenge_view.php';
