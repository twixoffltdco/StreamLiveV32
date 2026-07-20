<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/antibot.php';

header('Content-Type: image/svg+xml');
header('Cache-Control: no-store, no-cache, must-revalidate');

antibot_ensure_table();
$ip = antibot_client_ip();
$stmt = db()->prepare('SELECT captcha_code FROM antibot_ip_log WHERE ip = ?');
$stmt->execute([$ip]);
$code = $stmt->fetchColumn();
if (!$code) $code = antibot_new_code($ip);

$width = 220; $height = 90;
$colors = ['#fe2c55', '#25f4ee', '#ff6b8b', '#7c5cff', '#ffd166'];

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="100%">';
$svg .= '<rect width="' . $width . '" height="' . $height . '" fill="#0b0b10"/>';

// Шум — случайные линии, чтобы мешать автоматическому распознаванию
for ($i = 0; $i < 6; $i++) {
  $x1 = random_int(0, $width); $y1 = random_int(0, $height);
  $x2 = random_int(0, $width); $y2 = random_int(0, $height);
  $svg .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#2a2a35" stroke-width="2"/>';
}
// Шум — случайные точки
for ($i = 0; $i < 40; $i++) {
  $cx = random_int(0, $width); $cy = random_int(0, $height);
  $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="1.4" fill="#2a2a35"/>';
}

// mb_str_split требует PHP 7.4+ — на некоторых бесплатных хостингах может быть версия
// старше, поэтому бьём строку на символы через preg_split (работает с PHP 5.x).
$chars = preg_split('//u', $code, -1, PREG_SPLIT_NO_EMPTY);
$n = count($chars);
$segW = $width / ($n + 1);
foreach ($chars as $i => $ch) {
  $x = (int)($segW * ($i + 1));
  $y = (int)($height / 2) + random_int(-10, 10);
  $rotate = random_int(-25, 25);
  $size = random_int(30, 40);
  $color = $colors[array_rand($colors)];
  $svg .= '<text x="' . $x . '" y="' . $y . '" font-size="' . $size . '" font-weight="700" fill="' . $color . '" '
    . 'font-family="Arial, sans-serif" text-anchor="middle" transform="rotate(' . $rotate . ' ' . $x . ' ' . $y . ')">' . e($ch) . '</text>';
}
$svg .= '</svg>';

echo $svg;
