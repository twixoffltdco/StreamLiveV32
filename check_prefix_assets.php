<?php
header('Content-Type: text/plain; charset=utf-8');
$path = __DIR__ . '/includes/prefix_assets.php';
echo "file: $path\n";
echo "exists: " . (is_file($path) ? 'yes' : 'NO') . "\n";
if (!is_file($path)) exit;
$raw = file_get_contents($path);
echo "size: " . strlen($raw) . "\n";
echo "starts_with_php: " . (preg_match('/^\xEF\xBB\xBF?<\?php/', $raw) ? 'YES' : 'NO — THIS IS THE BUG') . "\n";
echo "first_80: " . str_replace("\n", "\\n", substr($raw, 0, 80)) . "\n";
echo "\nDelete this check_prefix_assets.php after use.\n";
