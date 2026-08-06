<?php
/**
 * Production PWA meta — подключается из header.php
 */
$__pwaName = defined('SITE_NAME') ? (string)SITE_NAME : 'StreamLive';
$__pwaName = $__pwaName !== '' ? $__pwaName : 'StreamLive';
?>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0f0f0f">
<meta name="color-scheme" content="dark light">
<meta name="mobile-web-app-capable" content="yes">
<meta name="application-name" content="<?= htmlspecialchars($__pwaName, ENT_QUOTES, 'UTF-8') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($__pwaName, ENT_QUOTES, 'UTF-8') ?>">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/icon-96.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192.png">
