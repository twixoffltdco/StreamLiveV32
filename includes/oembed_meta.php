<?php
function oembed_fetch_meta(string $url): array {
  $url = trim($url);
  $empty = ['title' => '', 'description' => '', 'thumbnail_url' => '', 'tags' => ''];
  if ($url === '' || !preg_match('#^https?://#i', $url)) return $empty;

  // YouTube oEmbed
  if (preg_match('~(?:youtube\.com|youtu\.be)~i', $url)) {
    $oe = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url);
    $j = oembed_http_json($oe);
    if ($j) {
      return [
        'title' => (string)($j['title'] ?? ''),
        'description' => '',
        'thumbnail_url' => (string)($j['thumbnail_url'] ?? ''),
        'tags' => '',
      ];
    }
  }
  // Vimeo
  if (preg_match('~vimeo\.com~i', $url)) {
    $j = oembed_http_json('https://vimeo.com/api/oembed.json?url=' . rawurlencode($url));
    if ($j) {
      return [
        'title' => (string)($j['title'] ?? ''),
        'description' => (string)($j['description'] ?? ''),
        'thumbnail_url' => (string)($j['thumbnail_url'] ?? ''),
        'tags' => '',
      ];
    }
  }
  // Rutube
  if (preg_match('~rutube\.ru/(?:video|play/embed)/([a-f0-9]{32})~i', $url, $m)) {
    $j = oembed_http_json('https://rutube.ru/api/video/' . $m[1] . '/?format=json');
    if ($j) {
      return [
        'title' => (string)($j['title'] ?? ''),
        'description' => (string)($j['description'] ?? ''),
        'thumbnail_url' => (string)($j['thumbnail_url'] ?? $j['poster_url'] ?? ''),
        'tags' => '',
      ];
    }
  }

  // OG scrape fallback
  $html = oembed_http_raw($url);
  if ($html === '') return $empty;
  $meta = $empty;
  if (preg_match('/property=["\']og:title["\'][^>]*content=["\']([^"\']+)/i', $html, $m)
    || preg_match('/content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\']/i', $html, $m)) {
    $meta['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }
  if (preg_match('/property=["\']og:description["\'][^>]*content=["\']([^"\']+)/i', $html, $m)
    || preg_match('/name=["\']description["\'][^>]*content=["\']([^"\']+)/i', $html, $m)) {
    $meta['description'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }
  if (preg_match('/property=["\']og:image["\'][^>]*content=["\']([^"\']+)/i', $html, $m)
    || preg_match('/content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']/i', $html, $m)) {
    $meta['thumbnail_url'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
  }
  return $meta;
}

function oembed_http_raw(string $url): string {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 8,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StreamLiveMeta/1.0)',
      CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = (string)curl_exec($ch);
    curl_close($ch);
    return $r;
  }
  $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: StreamLiveMeta/1.0\r\n"]]);
  return (string)@file_get_contents($url, false, $ctx);
}

function oembed_http_json(string $url): ?array {
  $r = oembed_http_raw($url);
  if ($r === '') return null;
  $j = json_decode($r, true);
  return is_array($j) ? $j : null;
}
