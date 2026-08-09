<?php
/**
 * После смены домена в embed_url/source_url часто остаются старые хосты (InfinityFree и т.д.)
 * или http:// на https-сайте — плеер молчит.
 */
function video_url_old_hosts(): array {
  return [
    'streamlive.freedev.app',
    'streamliveru.web1337.net',
    'web1337.net',
    'infinityfree.net',
    'epizy.com',
    'rf.gd',
  ];
}

function video_url_current_host(): string {
  if (defined('SITE_URL')) {
    $h = parse_url((string)SITE_URL, PHP_URL_HOST);
    if ($h) return strtolower($h);
  }
  return strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
}

function video_url_is_https(): bool {
  if (defined('SITE_URL') && stripos((string)SITE_URL, 'https://') === 0) return true;
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
  return false;
}

/**
 * Чинит URL для вывода в плеере (не обязательно пишет в БД).
 */
function video_url_fix_for_play(?string $url): string {
  $url = trim((string)$url);
  if ($url === '') return '';

  // Относительный путь нашего embed — ок
  if ($url[0] === '/' && (strpos($url, '//') !== 0)) {
    return $url;
  }

  $parts = @parse_url($url);
  if (!$parts || empty($parts['host'])) {
    return $url;
  }

  $host = strtolower($parts['host']);
  $hostNoWww = preg_replace('/^www\./', '', $host) ?: $host;
  $cur = video_url_current_host();
  $cur = preg_replace('/^www\./', '', $cur) ?: $cur;

  $scheme = $parts['scheme'] ?? 'https';
  $path = $parts['path'] ?? '/';
  $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
  $frag = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';

  // Наш же старый домен → текущий SITE_URL
  $olds = video_url_old_hosts();
  $isOldSelf = false;
  foreach ($olds as $oh) {
    if ($hostNoWww === $oh || str_ends_with($hostNoWww, '.' . $oh)) {
      $isOldSelf = true;
      break;
    }
  }
  // Также если host совпадает с бывшим, но не в списке — если path наш embed
  if ($cur && $hostNoWww !== $cur && $isOldSelf) {
    $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : ('https://' . $cur);
    return $base . $path . $query . $frag;
  }

  // http → https для того же хоста (mixed content)
  if ($scheme === 'http' && video_url_is_https()) {
    return 'https://' . $host . $path . $query . $frag;
  }

  return $url;
}

if (!function_exists('str_ends_with')) {
  function str_ends_with($haystack, $needle) {
    if ($needle === '') return true;
    return substr($haystack, -strlen($needle)) === $needle;
  }
}

/**
 * Применить фикс к массиву video (embed_url, source_url).
 */
function video_row_fix_urls(array $video): array {
  foreach (['embed_url', 'source_url', 'thumbnail_url', 'cover_url'] as $k) {
    if (!empty($video[$k])) {
      $video[$k] = video_url_fix_for_play((string)$video[$k]);
    }
  }
  return $video;
}
