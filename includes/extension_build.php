<?php
/**
 * Сборка zip расширения под текущий домен. Кэш на диске — без нагрузки на каждый хит.
 */
function extension_site_base(): string {
  if (defined('SITE_URL') && SITE_URL) {
    return rtrim((string)SITE_URL, '/');
  }
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return ($https ? 'https://' : 'http://') . $host;
}

function extension_src_dir(): string {
  return dirname(__DIR__) . '/extension_src';
}

function extension_cache_dir(): string {
  $d = dirname(__DIR__) . '/storage/extension_cache';
  if (!is_dir($d)) @mkdir($d, 0755, true);
  return $d;
}

/**
 * @return string|null path to zip or null
 */
function extension_build_zip(): ?string {
  if (!class_exists('ZipArchive')) {
    return null;
  }
  $base = extension_site_base();
  $origin = $base;
  $host = parse_url($base, PHP_URL_HOST) ?: 'localhost';
  $url = $base . '/?source=extension';

  $key = substr(md5($base), 0, 12);
  $cache = extension_cache_dir() . '/streamlive-ext-' . $key . '.zip';

  // кэш 24ч
  if (is_file($cache) && (time() - filemtime($cache)) < 86400) {
    return $cache;
  }

  $src = extension_src_dir();
  if (!is_dir($src)) return null;

  $replace = static function (string $text) use ($url, $origin, $host): string {
    return str_replace(
      ['{{SITE_URL}}', '{{SITE_ORIGIN}}', '{{SITE_HOST}}'],
      [$url, $origin, $host],
      $text
    );
  };

  $zip = new ZipArchive();
  $tmp = $cache . '.tmp';
  if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    return is_file($cache) ? $cache : null;
  }

  $files = [
    'manifest.json',
    'background.js',
    'options.html',
    'options.js',
  ];
  foreach ($files as $f) {
    $path = $src . '/' . $f;
    if (!is_file($path)) continue;
    $zip->addFromString($f, $replace((string)file_get_contents($path)));
  }
  foreach ([16, 32, 48, 128] as $s) {
    $ip = $src . '/icons/icon-' . $s . '.png';
    if (is_file($ip)) {
      $zip->addFile($ip, 'icons/icon-' . $s . '.png');
    }
  }
  $zip->close();
  @rename($tmp, $cache);
  return is_file($cache) ? $cache : null;
}
