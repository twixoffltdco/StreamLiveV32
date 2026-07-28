<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/antibot.php';

antibot_ensure_table();
$ip = antibot_client_ip();
$submitted = mb_strtoupper(trim($_POST['code'] ?? ''));
$returnTo = $_POST['return_to'] ?? '/';
// Разрешаем возврат только на свой же сайт (относительный путь) — чтобы капчу нельзя
// было использовать как open-redirect на посторонний сайт.
if ($returnTo === '' || $returnTo[0] !== '/' || substr($returnTo, 0, 2) === '//') $returnTo = '/';

$stmt = db()->prepare('SELECT captcha_code, captcha_expires FROM antibot_ip_log WHERE ip = ?');
$stmt->execute([$ip]);
$row = $stmt->fetch();

$valid = $row && $row['captcha_code'] && $submitted === mb_strtoupper($row['captcha_code'])
  && $row['captcha_expires'] && strtotime($row['captcha_expires']) > time();

if ($valid) {
  antibot_mark_captcha_passed($ip);
  header('Location: ' . $returnTo);
  exit;
}

header('Location: /antibot_challenge.php?bad=1&return_to=' . urlencode($returnTo));
