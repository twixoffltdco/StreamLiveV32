<?php
/**
 * Универсальный плеер StreamLive / embedwebsite
 *
 * ?url=https://...           — страница с плеером + meta
 * ?url=...&embed=1           — только плеер (для iframe на video.php)
 * ?url=...&meta=1            — JSON {title,description,thumbnail,tags,player_type,player_src}
 *
 * Спец-обработчики: Dropbox, Instagram.
 * Остальное — парсинг og:/iframe/video/hls.
 * Сеть: NETWORK_PROXY_URL из config (cors-anywhere / query).
 */
declare(strict_types=1);

// ---- config (прокси) ----
$configCandidates = [
  dirname(__DIR__) . '/config/config.php',
  dirname(__DIR__) . '/includes/db.php',
];
foreach ($configCandidates as $cfg) {
  if (is_file($cfg)) {
    // config.php может требовать константы — подключаем мягко
    try { @require_once $cfg; } catch (Throwable $e) {}
    break;
  }
}
// fallback: подтянуть functions если есть
$fn = dirname(__DIR__) . '/includes/functions.php';
if (is_file($fn)) {
  try { @require_once $fn; } catch (Throwable $e) {}
}

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
$embedMode = !empty($_GET['embed']);
$metaMode = !empty($_GET['meta']);

if ($url === '' || !preg_match('#^https?://#i', $url)) {
  http_response_code(400);
  if ($metaMode) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Укажи ?url=https://...'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  die('<h1 style="text-align:center;margin:80px;color:#eee;font-family:sans-serif">Укажи ссылку: ?url=https://example.com/video</h1>');
}

// SSRF guard: только http(s), не localhost/private
$host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
$host = preg_replace('/^www\./', '', $host) ?: '';
if ($host === '' || $host === 'localhost' || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)) {
  http_response_code(403);
  die($metaMode ? json_encode(['ok'=>false,'error'=>'host blocked']) : 'Host blocked');
}

// ---- HTTP fetch with proxy ----
function ew_proxy_wrap(string $target): string {
  if (!defined('NETWORK_PROXY_URL') || NETWORK_PROXY_URL === '') return $target;
  $base = rtrim((string)NETWORK_PROXY_URL, '/');
  $style = defined('NETWORK_PROXY_STYLE') ? strtolower((string)NETWORK_PROXY_STYLE) : '';
  if ($style === '' && strpos($base, '?') === false && (
      strpos($base, 'tatnet.app') !== false ||
      strpos($base, 'cors-anywhere') !== false ||
      strpos($base, 'corsanywhere') !== false
  )) {
    $style = 'cors_anywhere';
  }
  if (in_array($style, ['cors_anywhere', 'path', 'cors'], true)) {
    return $base . '/' . $target;
  }
  $sep = (strpos($base, '?') !== false) ? '&' : '?';
  return $base . $sep . 'url=' . rawurlencode($target);
}

function ew_http_get(string $target, int $timeout = 15): ?string {
  $headers = [
    'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
    'Accept-Language: ru-RU,ru;q=0.9,en;q=0.7',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
  ];
  $fetch = ew_proxy_wrap($target);
  if ($fetch !== $target) {
    $headers[] = 'X-Requested-With: XMLHttpRequest';
    $headers[] = 'Origin: ' . (defined('SITE_URL') ? (string)SITE_URL : 'https://localhost');
  }
  $ch = curl_init($fetch);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_ENCODING => '',
    CURLOPT_HTTPHEADER => $headers,
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if (!is_string($body) || $body === '' || $code >= 400) return null;
  return $body;
}

function ew_to_absolute(string $rel, string $base): string {
  $rel = trim($rel);
  if ($rel === '') return $rel;
  if (preg_match('#^https?://#i', $rel)) return $rel;
  if (strpos($rel, '//') === 0) return 'https:' . $rel;
  $p = parse_url($base);
  $scheme = $p['scheme'] ?? 'https';
  $h = $p['host'] ?? '';
  if ($rel[0] === '/') return $scheme . '://' . $h . $rel;
  $dir = dirname($p['path'] ?? '/');
  if ($dir === '\\' || $dir === '.') $dir = '/';
  if (substr($dir, -1) !== '/') $dir .= '/';
  return $scheme . '://' . $h . $dir . $rel;
}

function ew_extract_og(string $html): array {
  $get = function (string $attr, string $name) use ($html): string {
    if (preg_match('#<meta[^>]+' . $attr . '=["\']' . preg_quote($name, '#') . '["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m)) {
      return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+' . $attr . '=["\']' . preg_quote($name, '#') . '["\']#i', $html, $m)) {
      return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return '';
  };
  $title = $get('property', 'og:title') ?: $get('name', 'twitter:title');
  if ($title === '' && preg_match('#<title>(.*?)</title>#is', $html, $m)) {
    $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }
  $desc = $get('property', 'og:description') ?: $get('name', 'description') ?: $get('name', 'twitter:description');
  $image = $get('property', 'og:image') ?: $get('name', 'twitter:image');
  $video = $get('property', 'og:video') ?: $get('property', 'og:video:url');
  return compact('title', 'desc', 'image', 'video');
}

// ---- Special handlers ----
$result = [
  'title' => '',
  'description' => '',
  'thumbnail' => '',
  'tags' => '',
  'player_type' => '', // iframe | video | hls
  'player_src' => '',
];

// DROPBOX
if (strpos($host, 'dropbox') !== false) {
  $direct = $url;
  // /s/HASH/file
  if (preg_match('#dropbox\.com/s/([a-zA-Z0-9]+)/([^?\s]+)#i', $url, $m)) {
    $direct = 'https://dl.dropboxusercontent.com/s/' . $m[1] . '/' . $m[2] . '?raw=1';
  } elseif (preg_match('#dropbox\.com/scl/fi/([a-zA-Z0-9]+)/([^?\s]+)#i', $url, $m)) {
    $qs = [];
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?: ''), $qs);
    $params = ['raw' => '1'];
    if (!empty($qs['rlkey'])) $params['rlkey'] = $qs['rlkey'];
    if (!empty($qs['st'])) $params['st'] = $qs['st'];
    $direct = 'https://dl.dropboxusercontent.com/scl/fi/' . $m[1] . '/' . $m[2] . '?' . http_build_query($params);
  } elseif (stripos($url, 'dropboxusercontent.com') !== false) {
    if (!preg_match('/[?&](raw|dl)=1/', $url)) {
      $direct = $url . (strpos($url, '?') !== false ? '&' : '?') . 'raw=1';
    }
  } else {
    $direct = preg_replace('/([?&])dl=0(&|$)/', '$1', $url);
    $direct = rtrim($direct, '?&');
    if (!preg_match('/[?&](raw|dl)=1/', $direct)) {
      $direct .= (strpos($direct, '?') !== false ? '&' : '?') . 'raw=1';
    }
    $direct = preg_replace('#://www\.dropbox\.com/#i', '://dl.dropboxusercontent.com/', $direct);
  }

  $path = urldecode((string)(parse_url($url, PHP_URL_PATH) ?? ''));
  $base = basename($path);
  $title = $base && $base !== 'home' ? trim(str_replace(['+', '_'], ' ', preg_replace('/\.[a-z0-9]{2,5}$/i', '', $base))) : 'Видео Dropbox';
  $result['title'] = $title;
  $result['player_type'] = 'video';
  $result['player_src'] = $direct;

  // meta с share-страницы
  $share = preg_replace('/([?&])(raw|dl)=[01]/', '$1', $url);
  $share = rtrim($share, '?&');
  $html = ew_http_get($share, 12);
  if ($html) {
    $og = ew_extract_og($html);
    if ($og['title'] !== '') $result['title'] = $og['title'];
    if ($og['desc'] !== '') $result['description'] = $og['desc'];
    if ($og['image'] !== '') $result['thumbnail'] = $og['image'];
  }
}


// DROPBOX THUMB: картинки = сам файл; видео — preview если доступен
if (strpos($host, 'dropbox') !== false || strpos($host, 'dropboxusercontent') !== false) {
  $path = parse_url($result['player_src'] ?: $url, PHP_URL_PATH) ?: '';
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true)) {
    $result['thumbnail'] = $result['player_src'] ?: $direct ?? $url;
    if (($result['player_type'] ?? '') === '') {
      $result['player_type'] = 'image';
      $result['player_src'] = $result['thumbnail'];
    }
  } elseif (in_array($ext, ['mp4','webm','mov','m4v'], true)) {
    // Dropbox не отдаёт кадр — ставим data-uri placeholder или og если был
    if ($result['thumbnail'] === '') {
      // пробуем raw=1 → dl=1 preview не работает; оставляем пусто + клиент покажет video poster
      $result['thumbnail'] = '';
    }
    $result['player_type'] = 'video';
    if (empty($result['player_src']) && !empty($direct)) $result['player_src'] = $direct;
  }
}

// INSTAGRAM
elseif (strpos($host, 'instagram.com') !== false) {
  $code = '';
  if (preg_match('#instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)#i', $url, $m)) {
    $code = $m[1];
  }
  $embedIg = $code !== ''
    ? 'https://www.instagram.com/p/' . $code . '/embed/captioned/'
    : $url;

  $result['player_type'] = 'iframe';
  $result['player_src'] = $embedIg;
  $result['title'] = 'Публикация Instagram';

  // oEmbed
  $oeUrl = 'https://api.instagram.com/oembed?omitscript=true&url=' . rawurlencode(
    $code !== '' ? 'https://www.instagram.com/p/' . $code . '/' : $url
  );
  $raw = ew_http_get($oeUrl, 12);
  if ($raw) {
    $j = json_decode($raw, true);
    if (is_array($j)) {
      if (!empty($j['title'])) $result['title'] = (string)$j['title'];
      if (!empty($j['thumbnail_url'])) $result['thumbnail'] = (string)$j['thumbnail_url'];
      if (!empty($j['author_name'])) $result['description'] = '@' . $j['author_name'];
    }
  }
  // OG / mirror
  if ($result['thumbnail'] === '' || $result['title'] === 'Публикация Instagram') {
    foreach (array_filter([
      $code ? 'https://www.instagram.com/p/' . $code . '/' : null,
      $code ? 'https://www.ddinstagram.com/p/' . $code . '/' : null,
      $embedIg,
    ]) as $try) {
      $html = ew_http_get($try, 12);
      if (!$html) continue;
      $og = ew_extract_og($html);
      if ($og['title'] !== '' && $result['title'] === 'Публикация Instagram') {
        $result['title'] = preg_replace('/\s*[•|].*$/u', '', $og['title']);
      }
      if ($og['desc'] !== '' && $result['description'] === '') $result['description'] = $og['desc'];
      if ($og['image'] !== '' && $result['thumbnail'] === '') $result['thumbnail'] = $og['image'];
      if ($result['thumbnail'] !== '' && $result['title'] !== 'Публикация Instagram') break;
    }
  }
}


// VK VIDEO
elseif (strpos($host, 'vk.com') !== false || strpos($host, 'vkvideo.ru') !== false || strpos($host, 'vk.ru') !== false) {
  $result['title'] = 'Видео VK';
  $oid = $vid = '';
  if (preg_match('#(?:video|clip)(-?\d+)_(\d+)#i', $url, $m)) {
    $oid = $m[1];
    $vid = $m[2];
  } elseif (preg_match('#[?&]z=video(-?\d+)_(\d+)#i', $url, $m)) {
    $oid = $m[1];
    $vid = $m[2];
  }
  if ($oid !== '' && $vid !== '') {
    $result['player_type'] = 'iframe';
    $result['player_src'] = 'https://vk.com/video_ext.php?oid=' . rawurlencode($oid) . '&id=' . rawurlencode($vid) . '&hd=2';
  }
  $html = ew_http_get($url, 12);
  if ($html) {
    $og = ew_extract_og($html);
    if ($og['title'] !== '') $result['title'] = $og['title'];
    if ($og['desc'] !== '') $result['description'] = $og['desc'];
    if ($og['image'] !== '') $result['thumbnail'] = $og['image'];
    if ($og['video'] !== '' && ($result['player_src'] ?? '') === '') {
      $result['player_type'] = 'iframe';
      $result['player_src'] = $og['video'];
    }
  }
  // oEmbed-ish mobile
  if ($result['thumbnail'] === '' && $oid !== '' && $vid !== '') {
    $try = 'https://vk.com/video' . $oid . '_' . $vid;
    $html2 = ew_http_get($try, 10);
    if ($html2) {
      $og2 = ew_extract_og($html2);
      if ($og2['image'] !== '') $result['thumbnail'] = $og2['image'];
      if ($og2['title'] !== '' && $result['title'] === 'Видео VK') $result['title'] = $og2['title'];
    }
  }
}

// TWITCH
elseif (strpos($host, 'twitch.tv') !== false) {
  $result['title'] = 'Twitch';
  $channel = $videoId = '';
  if (preg_match('#twitch\.tv/videos/(\d+)#i', $url, $m)) {
    $videoId = $m[1];
    $result['player_type'] = 'iframe';
    $result['player_src'] = 'https://player.twitch.tv/?video=' . $videoId . '&parent=' . rawurlencode($_SERVER['HTTP_HOST'] ?? 'localhost') . '&autoplay=false';
  } elseif (preg_match('#twitch\.tv/([A-Za-z0-9_]+)/clip/([A-Za-z0-9_-]+)#i', $url, $m)) {
    $result['player_type'] = 'iframe';
    $result['player_src'] = 'https://clips.twitch.tv/embed?clip=' . rawurlencode($m[2]) . '&parent=' . rawurlencode($_SERVER['HTTP_HOST'] ?? 'localhost');
    $result['title'] = 'Twitch Clip';
  } elseif (preg_match('#twitch\.tv/([A-Za-z0-9_]+)/?$#i', $url, $m)) {
    $channel = $m[1];
    $result['player_type'] = 'iframe';
    $result['player_src'] = 'https://player.twitch.tv/?channel=' . rawurlencode($channel) . '&parent=' . rawurlencode($_SERVER['HTTP_HOST'] ?? 'localhost') . '&autoplay=false';
    $result['title'] = 'Twitch: ' . $channel;
  }
  $html = ew_http_get($url, 12);
  if ($html) {
    $og = ew_extract_og($html);
    if ($og['title'] !== '') $result['title'] = $og['title'];
    if ($og['desc'] !== '') $result['description'] = $og['desc'];
    if ($og['image'] !== '') $result['thumbnail'] = $og['image'];
  }
}

// GENERIC
else {
  $html = ew_http_get($url, 15);
  if (!$html) {
    if ($metaMode) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'Не удалось загрузить страницу (прокси/сеть)'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    die('<div style="color:#eee;font-family:sans-serif;padding:40px;text-align:center">Не удалось загрузить страницу. Проверь NETWORK_PROXY_URL в config.</div>');
  }
  $og = ew_extract_og($html);
  $result['title'] = $og['title'];
  $result['description'] = $og['desc'];
  $result['thumbnail'] = $og['image'];

  $iframe_url = $video_url = $hls_url = '';

  if (preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
    foreach ($matches[1] as $src) {
      $src = ew_to_absolute($src, $url);
      if (preg_match('/(player|embed|video|rutube|youtube|youtu\.be|vimeo|vk\.com|ok\.ru|instagram)/i', $src)) {
        $iframe_url = $src;
        break;
      }
    }
    if ($iframe_url === '' && !empty($matches[1][0])) {
      $iframe_url = ew_to_absolute($matches[1][0], $url);
    }
  }
  if ($iframe_url === '' && preg_match('/<video[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
    $video_url = ew_to_absolute($m[1], $url);
  }
  if ($iframe_url === '' && $video_url === '') {
    if (preg_match_all('/<source[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
      foreach ($matches[1] as $src) {
        $src = ew_to_absolute($src, $url);
        if (preg_match('/\.m3u8/i', $src)) $hls_url = $src;
        else $video_url = $src;
      }
    }
  }
  if ($iframe_url === '' && $video_url === '' && $hls_url === '') {
    if (preg_match('/(https?:\/\/[^\s"\']+\.m3u8[^\s"\']*)/i', $html, $m)) {
      $hls_url = html_entity_decode($m[1]);
    } elseif (preg_match('/(https?:\/\/[^\s"\']+\.(?:mp4|webm)[^\s"\']*)/i', $html, $m)) {
      $video_url = html_entity_decode($m[1]);
    }
  }
  if ($og['video'] !== '' && $iframe_url === '' && $video_url === '' && $hls_url === '') {
    $maybe = ew_to_absolute($og['video'], $url);
    if (preg_match('/\.m3u8/i', $maybe)) $hls_url = $maybe;
    elseif (preg_match('/embed|player/i', $maybe)) $iframe_url = $maybe;
    else $video_url = $maybe;
  }

  if ($iframe_url !== '') {
    $result['player_type'] = 'iframe';
    $result['player_src'] = $iframe_url;
  } elseif ($hls_url !== '') {
    $result['player_type'] = 'hls';
    $result['player_src'] = $hls_url;
  } elseif ($video_url !== '') {
    $result['player_type'] = 'video';
    $result['player_src'] = $video_url;
  }
}

// Manual overrides
if (!empty($_GET['title'])) $result['title'] = trim((string)$_GET['title']);
if (!empty($_GET['description'])) $result['description'] = trim((string)$_GET['description']);
if (!empty($_GET['image'])) $result['thumbnail'] = trim((string)$_GET['image']);
if (!empty($_GET['iframe'])) { $result['player_type'] = 'iframe'; $result['player_src'] = trim((string)$_GET['iframe']); }
if (!empty($_GET['hls'])) { $result['player_type'] = 'hls'; $result['player_src'] = trim((string)$_GET['hls']); }
if (!empty($_GET['video'])) { $result['player_type'] = 'video'; $result['player_src'] = trim((string)$_GET['video']); }

// tags from title
if ($result['tags'] === '' && $result['title'] !== '') {
  $stop = ['для','это','как','что','видео','смотреть','онлайн','the','and','for','with','instagram','dropbox'];
  preg_match_all('/[а-яa-z0-9]{4,}/u', mb_strtolower($result['title'] . ' ' . $result['description']), $wm);
  $words = array_values(array_diff(array_unique($wm[0] ?? []), $stop));
  $result['tags'] = implode(', ', array_slice($words, 0, 8));
}

if ($metaMode) {
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  echo json_encode([
    'ok' => $result['player_src'] !== '' || $result['title'] !== '',
    'title' => $result['title'],
    'description' => $result['description'],
    'thumbnail_url' => $result['thumbnail'],
    'tags' => $result['tags'],
    'player_type' => $result['player_type'],
    'player_src' => $result['player_src'],
    'meta_source' => 'embedwebsite',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($result['player_src'] === '') {
  http_response_code(422);
  die('<div style="color:#eee;font-family:sans-serif;padding:40px;text-align:center">Не удалось найти плеер на странице</div>');
}

$title = $result['title'] ?: 'Видео';
$description = $result['description'];
$image = $result['thumbnail'];
$player_type = $result['player_type'];
$player_src = $result['player_src'];

// ---- HTML ----
if ($embedMode):
?>
<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<style>
html,body{margin:0;padding:0;width:100%;height:100%;background:#000;overflow:hidden}
.player{position:absolute;inset:0}
.player iframe,.player video{width:100%;height:100%;border:0;background:#000}
</style>
</head><body><div class="player">
<?php if ($player_type === 'iframe'): ?>
<iframe src="<?= htmlspecialchars($player_src) ?>" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture"></iframe>
<?php elseif ($player_type === 'hls'): ?>
<video id="v" controls playsinline></video>
<script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
<script>
(function(){var s=<?= json_encode($player_src) ?>,v=document.getElementById('v');
if(window.Hls&&Hls.isSupported()){var h=new Hls();h.loadSource(s);h.attachMedia(v);}else{v.src=s;}})();
</script>
<?php else: ?>
<video src="<?= htmlspecialchars($player_src) ?>" controls playsinline<?= $image ? ' poster="'.htmlspecialchars($image).'"' : '' ?>></video>
<?php endif; ?>
</div></body></html>
<?php
exit;
endif;

// full page (preview / share)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hostHdr = $_SERVER['HTTP_HOST'] ?? 'localhost';
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$current = $scheme . '://' . $hostHdr . $reqUri;
$embedLink = (strpos($current, 'embed=') !== false) ? $current : ($current . (strpos($current, '?') !== false ? '&' : '?') . 'embed=1');
?>
<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<meta name="description" content="<?= htmlspecialchars($description) ?>">
<meta property="og:title" content="<?= htmlspecialchars($title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($description) ?>">
<meta property="og:image" content="<?= htmlspecialchars($image) ?>">
<meta property="og:type" content="video.other">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0f0f0f;color:#f1f1f1;font-family:Roboto,system-ui,sans-serif;min-height:100vh}
.wrap{max-width:960px;margin:0 auto;padding:16px}
.player{position:relative;padding-top:56.25%;background:#000;border-radius:12px;overflow:hidden}
.player iframe,.player video{position:absolute;inset:0;width:100%;height:100%;border:0}
h1{font-size:20px;font-weight:600;margin:16px 0 8px;line-height:1.35}
.meta{color:#aaa;font-size:14px;margin-bottom:12px}
.desc{background:#272727;border-radius:12px;padding:12px 14px;font-size:14px;line-height:1.5;white-space:pre-wrap}
</style>
</head><body>
<div class="wrap">
  <div class="player">
<?php if ($player_type === 'iframe'): ?>
    <iframe src="<?= htmlspecialchars($player_src) ?>" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture"></iframe>
<?php elseif ($player_type === 'hls'): ?>
    <video id="v" controls playsinline></video>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
    <script>
    (function(){var s=<?= json_encode($player_src) ?>,v=document.getElementById('v');
    if(window.Hls&&Hls.isSupported()){var h=new Hls();h.loadSource(s);h.attachMedia(v);}else{v.src=s;}})();
    </script>
<?php else: ?>
    <video src="<?= htmlspecialchars($player_src) ?>" controls playsinline<?= $image ? ' poster="'.htmlspecialchars($image).'"' : '' ?>></video>
<?php endif; ?>
  </div>
  <h1><?= htmlspecialchars($title) ?></h1>
  <?php if ($description): ?><div class="desc"><?= htmlspecialchars($description) ?></div><?php endif; ?>
</div>
</body></html>
