<?php
// Подключить из header перед </head> и в footer:
// <?php @include __DIR__ . '/prefix_assets.php'; ?>
if (!defined('SL_PREFIX_ASSETS')) {
  define('SL_PREFIX_ASSETS', 1);
  echo '<link rel="stylesheet" href="/assets/css/user-display.css?v=pfx3">';
  echo '<script src="/assets/js/prefix-dedupe.js?v=1" defer></script>';
}
