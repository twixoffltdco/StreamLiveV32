<?php
/**
 * Скачать расширение (zip) под текущий домен.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/extension_build.php';

// лёгкий throttle: не чаще 1 раза / 10 сек с IP (free host)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$tf = sys_get_temp_dir() . '/sl_ext_' . md5($ip);
if (is_file($tf) && (time() - (int)file_get_contents($tf)) < 10) {
  http_response_code(429);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Подождите несколько секунд и повторите скачивание.\n";
  exit;
}
@file_put_contents($tf, (string)time());

$zip = extension_build_zip();
if (!$zip || !is_file($zip)) {
  http_response_code(503);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Сборка расширения недоступна (нужен ZipArchive). Скачайте архив вручную из /extension_src или установите php-zip.\n";
  exit;
}

$host = parse_url(extension_site_base(), PHP_URL_HOST) ?: 'streamlive';
$filename = 'StreamLive-extension-' . preg_replace('/[^a-z0-9.-]/i', '', $host) . '.zip';

header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zip));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=3600');
readfile($zip);
exit;
