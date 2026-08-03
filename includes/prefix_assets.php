<?php
/**
 * CSS/JS для префиксов. Только через include из header.
 * Не выводит ничего, кроме link/script внутри PHP.
 */
if (defined('SL_PREFIX_ASSETS')) {
  return;
}
define('SL_PREFIX_ASSETS', 1);

$css = __DIR__ . '/../assets/css/user-display.css';
$js  = __DIR__ . '/../assets/js/prefix-dedupe.js';
$cssV = is_file($css) ? (string)filemtime($css) : '1';
$jsV  = is_file($js) ? (string)filemtime($js) : '1';

echo '<link rel="stylesheet" href="/assets/css/user-display.css?v=' . htmlspecialchars($cssV, ENT_QUOTES, 'UTF-8') . '">' . "\n";
if (is_file($js)) {
  echo '<script src="/assets/js/prefix-dedupe.js?v=' . htmlspecialchars($jsV, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
}
