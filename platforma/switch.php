<?php
declare(strict_types=1);
$mode = strtolower(trim((string)($_GET['mode'] ?? 'streamlife')));
$allowed = ['streamlife', 'platforma', 'telegram'];
if (!in_array($mode, $allowed, true)) {
  $mode = 'streamlife';
}
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('pl_ui_mode', $mode, [
  'expires' => time() + 86400 * 365,
  'path' => '/',
  'secure' => $secure,
  'httponly' => false,
  'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['pl_ui_mode'] = $mode;
$redirect = (string)($_GET['redirect'] ?? '/');
if ($redirect === '' || preg_match('#^(https?:)?//#i', $redirect)) $redirect = '/';
if ($redirect[0] !== '/') $redirect = '/' . $redirect;
header('Cache-Control: no-store');
header('Location: ' . $redirect, true, 302);
exit;
