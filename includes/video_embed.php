<?php
// Универсальный модуль видеохостинга: распознаёт ссылку с любой поддерживаемой
// платформы, приводит её к embed-варианту и пытается автоматически вытащить
// название/описание/обложку/теги — либо через официальный oEmbed (YouTube, Vimeo,
// TikTok, DailyMotion), либо через og:-теги/JSON-LD самой страницы (VK, Rutube, ok.ru и т.д.).
//
// ПОЧЕМУ АВТОЗАПОЛНЕНИЕ НЕ РАБОТАЛО ВООБЩЕ НИ ДЛЯ ОДНОЙ ПЛОЩАДКИ (включая YouTube) —
// diagnose_network.php это подтвердил: на InfinityFree и похожих бесплатных хостингах
// исходящие curl-запросы наружу массово режутся/банятся хостером (не отдельные площадки
// блокируют бота — блокируется вообще любой внешний адрес с этого сервера, включая
// api.github.com). Поэтому теперь есть три уровня фолбэка по возрастанию надёжности:
//
//   1) NETWORK_PROXY_URL (свой Vercel-прокси, см. proxy-worker/ и README_NETWORK_PROXY.md) —
//      ЕСЛИ он задан в config/config.php, используется В ПЕРВУЮ ОЧЕРЕДЬ для всех запросов,
//      это единственный способ, гарантированно работающий именно на твоём хостинге.
//   2) Прямой curl с сервера — если прокси не настроен, пробуем как раньше напрямую
//      (сработает на хостинге без ограничений на исходящий трафик).
//   3) Публичные read/CORS-прокси (allorigins/codetabs/corsproxy) — последний шанс,
//      если ни свой прокси не настроен, ни прямой доступ не работает.
//
// Публичные функции:
//   detect_video_platform(string $url): ?string
//   normalize_video_embed(string $platform, string $url): string
//   fetch_video_meta(string $url, string $platform): array  // [title, description, thumbnail_url, tags, meta_source]
//   guess_video_tags(string $title, string $description): string


/**
 * Локальные парсеры проекта (НЕ внешние iframe сайтов целиком):
 *  - Смотрим → /playersmotrimru.php?id=…
 *  - любой неизвестный сайт → /embedwebsite/index.php?url=…&embed=1 (только плеер)
 * Без лишних зависимостей, без 500.
 */
function local_video_embed_url(string $url): ?string {
  $url = trim($url);
  if ($url === '' || !preg_match('#^https?://#i', $url)) {
    return null;
  }
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  $host = preg_replace('/^www\./', '', $host ?? '') ?: '';

  // ——— Смотрим: ваш playersmotrimru.php ———
  if ($host === 'smotrim.ru' || $host === 'player.smotrim.ru' || str_ends_with_safe($host, '.smotrim.ru')) {
    $id = 0;
    if (preg_match('#(?:smotrim\.ru/video/|player\.smotrim\.ru/iframe/video/id/)(\d+)#i', $url, $m)) {
      $id = (int)$m[1];
    } elseif (preg_match('/(\d{5,})/', $url, $m)) {
      $id = (int)$m[1];
    }
    if ($id > 0) {
      return '/playersmotrimru.php?id=' . $id;
    }
    // id не вытащили — пусть парсер сам разберёт из url
    return '/playersmotrimru.php?url=' . rawurlencode($url);
  }

  // ——— Неизвестный сайт: только embed-плеер, не полная страница ———
  return '/embedwebsite/index.php?url=' . rawurlencode($url) . '&embed=1';
}

function str_ends_with_safe(string $haystack, string $needle): bool {
  if ($needle === '') return true;
  if (function_exists('str_ends_with')) return str_ends_with($haystack, $needle);
  return substr($haystack, -strlen($needle)) === $needle;
}

function detect_video_platform(string $url): ?string {
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));
  $host = preg_replace('/^www\./', '', $host ?? '');

  $map = [
    'youtube.com'      => 'youtube',
    'youtu.be'         => 'youtube',
    'm.youtube.com'    => 'youtube',
    'music.youtube.com'=> 'youtube',
    'vk.com'           => 'vk',
    'vk.ru'            => 'vk',
    'm.vk.com'         => 'vk',
    'm.vk.ru'          => 'vk',
    'vkvideo.ru'       => 'vk',
    'm.vkvideo.ru'     => 'vk',
    'rutube.ru'        => 'rutube',
    'm.rutube.ru'      => 'rutube',
    'tiktok.com'       => 'tiktok',
    'vm.tiktok.com'    => 'tiktok',
    'vimeo.com'        => 'vimeo',
    'player.vimeo.com' => 'vimeo',
    'dailymotion.com'  => 'dailymotion',
    'dai.ly'           => 'dailymotion',
    'twitch.tv'          => 'twitch',
    'clips.twitch.tv'    => 'twitch',
    'kick.com'           => 'kick',
    'ok.ru'              => 'okru',
    'm.ok.ru'            => 'okru',
    'odnoklassniki.ru'   => 'okru',
    'm.odnoklassniki.ru' => 'okru',
    'coub.com'           => 'coub',
    'peertube.tv'        => 'peertube', // конкретные инстансы PeerTube определяются отдельно ниже
    'smotrim.ru'         => 'smotrim',
    'facebook.com'       => 'facebook',
    'fb.watch'           => 'facebook',
    'x.com'              => 'twitter',
    'twitter.com'        => 'twitter',
    'bitchute.com'       => 'bitchute',
    'streamable.com'     => 'streamable',
    'reddit.com'         => 'reddit',
    'drive.google.com'   => 'gdrive',
    'instagram.com'      => 'instagram',
    'www.instagram.com'  => 'instagram',
    'dropbox.com'        => 'dropbox',
    'www.dropbox.com'    => 'dropbox',
    'dl.dropboxusercontent.com' => 'dropbox',
    'dl.dropbox.com'     => 'dropbox',
    'plvideo.base44.app' => 'playtube',
    'russtube.ru'        => 'playtube',
    'нашютуб.рф'         => 'playtube',
    'xn--80a1acd.xn--p1ai'=> 'playtube', // нашютуб.рф punycode fallback
  ];
  if (isset($map[$host])) return $map[$host];

  // PeerTube — путь /w/ или /videos/watch/
  if (preg_match('#/(w|videos/watch)/[\w-]+#', (string)parse_url($url, PHP_URL_PATH))) return 'peertube';

  // PlayTube / клоны (plvideo, russtube, нашютуб и т.п.): /watch/ID, /v/ID, /embed/ID
  $path = (string)parse_url($url, PHP_URL_PATH);
  if (preg_match('#/(?:watch|v|embed|video)/([a-zA-Z0-9_-]{3,})#', $path)) {
    // не путаем с youtube/vimeo — они уже в map
    if (!in_array($host, ['youtube.com','youtu.be','vimeo.com','rutube.ru','vk.com','vk.ru'], true)) {
      return 'playtube';
    }
  }

  if (preg_match('/\.(mp4|webm|mov)(\?.*)?$/i', $url)) return 'mp4';
  if (preg_match('/\.m3u8(\?.*)?$/i', $url)) return 'm3u8';

  if ($host && (strpos($host, 'dropbox') !== false)) return 'dropbox';

  return $host ? 'iframe' : null; // неизвестный хост — попробуем как generic iframe
}

function normalize_video_embed(string $platform, string $url): string {
  switch ($platform) {
    case 'youtube':
      if (strpos($url, 'youtube.com/embed/') !== false) return $url;
      $id = null;
      if (preg_match('/[?&]v=([\w-]{6,})/', $url, $m)) $id = $m[1];
      if (!$id && preg_match('#youtu\.be/([\w-]{6,})#', $url, $m)) $id = $m[1];
      if (!$id && preg_match('#youtube\.com/shorts/([\w-]{6,})#', $url, $m)) $id = $m[1];
      return $id ? "https://www.youtube.com/embed/{$id}" : $url;

    case 'vk':
      if (strpos($url, 'video_ext.php') !== false) return $url;
      if (preg_match('/video(-?\d+)_(\d+)(?:\?.*hash=([\w]+))?/', $url, $m)) {
        $embed = "https://vk.com/video_ext.php?oid={$m[1]}&id={$m[2]}&hd=2";
        if (!empty($m[3])) $embed .= "&hash={$m[3]}";
        return $embed;
      }
      return $url;

    case 'rutube':
      if (strpos($url, '/play/embed/') !== false) return $url;
      if (preg_match('#rutube\.ru/video/([a-f0-9]+)#i', $url, $m)) {
        return "https://rutube.ru/play/embed/{$m[1]}/";
      }
      return $url;

    case 'vimeo':
      if (strpos($url, 'player.vimeo.com') !== false) return $url;
      if (preg_match('#vimeo\.com/(\d+)#', $url, $m)) {
        return "https://player.vimeo.com/video/{$m[1]}";
      }
      return $url;

    case 'dailymotion':
      if (strpos($url, '/embed/video/') !== false) return $url;
      if (preg_match('#dailymotion\.com/video/([a-zA-Z0-9]+)#', $url, $m)) {
        return "https://www.dailymotion.com/embed/video/{$m[1]}";
      }
      if (preg_match('#dai\.ly/([a-zA-Z0-9]+)#', $url, $m)) {
        return "https://www.dailymotion.com/embed/video/{$m[1]}";
      }
      return $url;

    case 'tiktok':
      // У TikTok нет простого публичного embed-URL по ID без их JS-виджета,
      // поэтому оставляем оригинальную ссылку — рендерим через их embed.js на странице просмотра.
      return $url;

    case 'twitch':
      // У Twitch плеер жёстко проверяет параметр parent — должен ТОЧНО совпадать с доменом
      // сайта, где встроен плеер, иначе показывает ошибку вместо видео. Раньше здесь была
      // заглушка REPLACE_WITH_YOUR_DOMAIN, которая никогда не подставлялась — эмбед был
      // гарантированно нерабочим. Берём домен из SITE_URL (задан в config/config.php).
      $parentDomain = defined('SITE_URL') ? (parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost') : 'localhost';
      if (preg_match('#twitch\.tv/videos/(\d+)#', $url, $m)) {
        return "https://player.twitch.tv/?video={$m[1]}&parent={$parentDomain}";
      }
      if (preg_match('#clips\.twitch\.tv/([\w-]+)#', $url, $m) || preg_match('#twitch\.tv/\w+/clip/([\w-]+)#', $url, $m)) {
        return "https://clips.twitch.tv/embed?clip={$m[1]}&parent={$parentDomain}";
      }
      return $url;

    case 'kick':
      if (preg_match('#kick\.com/([\w-]+)#', $url, $m)) {
        return "https://player.kick.com/{$m[1]}";
      }
      return $url;

    case 'okru':
      if (preg_match('#(?:ok\.ru|odnoklassniki\.ru)/(?:live/)?video/(\d+)#i', $url, $m)) {
        return "https://ok.ru/videoembed/{$m[1]}";
      }
      return $url;

    case 'coub':
      // Coub встраивается по числовому/буквенному ID из /view/<id>
      if (strpos($url, '/embed/') !== false) return $url;
      if (preg_match('#coub\.com/view/([a-zA-Z0-9]+)#', $url, $m)) {
        return "https://coub.com/embed/{$m[1]}";
      }
      return $url;

    case 'peertube':
      // /w/<id> и /videos/watch/<id> оба превращаются в /videos/embed/<id> на ЛЮБОМ инстансе —
      // домен берём из самой ссылки, т.к. инстансов множество.
      if (strpos($url, '/videos/embed/') !== false) return $url;
      $host = parse_url($url, PHP_URL_HOST);
      if ($host && preg_match('#/(?:w|videos/watch)/([\w-]+)#', $url, $m)) {
        return "https://{$host}/videos/embed/{$m[1]}";
      }
      return $url;


    case 'facebook':
      return 'https://www.facebook.com/plugins/video.php?href=' . urlencode($url) . '&show_text=false&width=560';

    case 'twitter':
      return $url;

    case 'bitchute':
      if (preg_match('#bitchute\.com/video/([a-zA-Z0-9_-]+)#', $url, $m)) return "https://www.bitchute.com/embed/{$m[1]}/";
      return $url;

    case 'streamable':
      if (preg_match('#streamable\.com/(?:e/)?([a-zA-Z0-9]+)#', $url, $m)) return "https://streamable.com/e/{$m[1]}";
      return $url;

    case 'reddit':
      return str_contains($url, '?') ? $url . '&embed=true' : $url . '?embed=true';

    case 'gdrive':
      if (preg_match('#/file/d/([^/]+)#', $url, $m)) return "https://drive.google.com/file/d/{$m[1]}/preview";
      return $url;

    case 'playtube':
      // PlayTube / plvideo.base44.app: /watch/ID → /embed/ID на том же хосте
      $hostPt = strtolower((string)parse_url($url, PHP_URL_HOST));
      $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
      $id = null;
      if (preg_match('#/(?:watch|v|embed|video)/([a-zA-Z0-9_-]{3,})#', $url, $m)) {
        $id = $m[1];
      } elseif (preg_match('/[?&](?:id|video_id)=([a-zA-Z0-9_-]+)/', $url, $m)) {
        $id = $m[1];
      }
      if ($id && $hostPt) {
        return $scheme . '://' . $hostPt . '/embed/' . rawurlencode($id);
      }
      // фолбэк — локальный скрапер плеера
      $local = local_video_embed_url($url);
      return $local !== null ? $local : $url;

    case 'smotrim':
      // Парсер проекта: playersmotrimru.php (не прямой player.smotrim.ru)
      $local = local_video_embed_url($url);
      return $local !== null ? $local : $url;


    case 'dropbox':
      // Прямой файл для <video>, не HTML-страница Dropbox
      if (strpos($url, 'dropboxusercontent.com') !== false) {
        return $url;
      }
      $u = preg_replace('/([?&])dl=0(&|$)/', '$1', $url);
      $u = rtrim($u, '?&');
      // raw=1 отдаёт бинарник; dl=1 тоже, но raw надёжнее для video src
      $u = preg_replace('/([?&])(dl|raw)=1/', '', $u);
      $u = rtrim($u, '?&');
      $u .= (strpos($u, '?') !== false ? '&' : '?') . 'raw=1';
      return $u;


    case 'instagram':
      // Сначала официальный embed; параллельно local_video_embed_url для нашего iframe-парсера
      if (preg_match('#instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)#', $url, $m)) {
        return 'https://www.instagram.com/p/' . $m[1] . '/embed/captioned/';
      }
      $local = local_video_embed_url($url);
      return $local !== null ? $local : $url;


    case 'iframe':
      // Неизвестный сайт → embedwebsite/index.php?url=…&embed=1 (только плеер)
      $local = local_video_embed_url($url);
      return $local !== null ? $local : $url;

    default:
      // mp4 / m3u8 и уже нормализованные платформы — без изменений
      if (in_array($platform, ['mp4', 'm3u8'], true)) {
        return $url;
      }
      // На всякий случай неизвестный label → тоже через локальный парсер
      $local = local_video_embed_url($url);
      return $local !== null ? $local : $url;
  }
}

// ---- Сеть: свой прокси (NETWORK_PROXY_URL) в приоритете, иначе прямой curl ----

function video_http_get(string $url, int $timeout = 8): ?string {
  $headers = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.9,image/webp,*/*;q=0.8',
    'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
  ];

  // Если в config.php задан свой Vercel-прокси — идём через него. Это приоритетный путь,
  // а не запасной: именно он реально работает на хостингах, режущих исходящий curl
  // (см. diagnose_network.php / README_NETWORK_PROXY.md).
  $actualUrl = $url;
  if (defined('NETWORK_PROXY_URL') && NETWORK_PROXY_URL !== '') {
    $actualUrl = NETWORK_PROXY_URL . '?url=' . urlencode($url);
    if (defined('NETWORK_PROXY_SECRET') && NETWORK_PROXY_SECRET !== '') {
      $headers[] = 'X-Proxy-Secret: ' . NETWORK_PROXY_SECRET;
    }
  }

  $ch = curl_init($actualUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_ENCODING       => '', // разрешаем gzip/deflate — часть площадок режут ответ без этого
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_COOKIE         => 'remixlang=0; remixstid=1', // чуть помогает VK отдать страницу видео, а не заглушку выбора языка
  ]);
  $body = curl_exec($ch);
  $err = curl_errno($ch);
  curl_close($ch);
  return ($err === 0 && is_string($body) && $body !== '') ? $body : null;
}

// Публичные read/CORS-прокси — последний шанс, если свой прокси не настроен, а прямой
// curl не проходит. Каждый запрос идёт со своим IP, который может быть не забанен площадкой.
function video_http_get_public_proxied(string $url, int $timeout = 8): ?string {
  $proxyUrls = [
    'https://api.allorigins.win/raw?url=' . urlencode($url),
    'https://api.codetabs.com/v1/proxy?quest=' . urlencode($url),
    'https://corsproxy.io/?url=' . urlencode($url),
  ];
  foreach ($proxyUrls as $proxyUrl) {
    $body = video_http_get($proxyUrl, $timeout); // пойдёт через NETWORK_PROXY_URL, если задан — тоже ок
    if ($body === null) continue;
    $og = video_extract_og_tags($body);
    if (!empty($og['title'])) return $body;
  }
  return null;
}

function video_fetch_with_fallback(string $url, int $timeout = 8): ?string {
  $direct = video_http_get($url, $timeout);
  if ($direct !== null) {
    $og = video_extract_og_tags($direct);
    if (!empty($og['title'])) return $direct;
  }
  // Если свой прокси уже настроен и всё равно не дал og:title — площадка реально не отдаёт
  // разметку (не проблема хостинга), но на всякий случай ещё пробуем публичные прокси —
  // вдруг конкретно эта площадка банит именно IP твоего Vercel-проекта.
  return video_http_get_public_proxied($url, $timeout) ?? $direct;
}

// Для платформ без oEmbed один и тот же URL иногда отдаёт заглушку, а его вариант
// (другой поддомен/embed-версия) — нормальную разметку. Пробуем несколько вариантов подряд.
function video_scrape_candidates(string $platform, string $url): array {
  $candidates = [$url];

  if ($platform === 'vk') {
    $alt = preg_replace('#^(https?://)(www\.|m\.)?vk\.(com|ru)#i', '$1vk.com', $url);
    if ($alt && !in_array($alt, $candidates, true)) $candidates[] = $alt;
    $embed = normalize_video_embed('vk', $url);
    if ($embed !== $url && !in_array($embed, $candidates, true)) $candidates[] = $embed;
  } elseif ($platform === 'okru') {
    $alt = preg_replace('#^(https?://)(m\.)?(ok\.ru|odnoklassniki\.ru)#i', '$1ok.ru', $url);
    if ($alt && !in_array($alt, $candidates, true)) $candidates[] = $alt;
  } elseif ($platform === 'rutube') {
    $alt = preg_replace('#^(https?://)m\.rutube\.ru#i', '$1rutube.ru', $url);
    if ($alt && !in_array($alt, $candidates, true)) $candidates[] = $alt;
  }

  return $candidates;
}

function video_oembed_url(string $platform, string $sourceUrl): ?string {
  switch ($platform) {
    case 'youtube':    return 'https://www.youtube.com/oembed?format=json&url=' . urlencode($sourceUrl);
    case 'vimeo':      return 'https://vimeo.com/api/oembed.json?url=' . urlencode($sourceUrl);
    case 'tiktok':     return 'https://www.tiktok.com/oembed?url=' . urlencode($sourceUrl);
    case 'dailymotion':return 'https://www.dailymotion.com/services/oembed?url=' . urlencode($sourceUrl);
    case 'coub':       return 'https://coub.com/api/oembed.json?url=' . urlencode($sourceUrl);
    case 'peertube':
      // У PeerTube оEmbed есть, но путь стандартный на любом инстансе — берём домен из ссылки.
      $host = parse_url($sourceUrl, PHP_URL_HOST);
      return $host ? "https://{$host}/services/oembed?url=" . urlencode($sourceUrl) : null;
    // У VK и Одноклассников официального публичного oEmbed нет (только через VK API
    // с access_token) — для них работает только og-теги/JSON-LD.
    default:           return null;
  }
}

function video_meta_tag(string $html, string $attr, string $name): ?string {
  if (preg_match('#<meta[^>]+' . $attr . '=["\']' . preg_quote($name, '#') . '["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m)) {
    return html_entity_decode($m[1], ENT_QUOTES);
  }
  if (preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]+' . $attr . '=["\']' . preg_quote($name, '#') . '["\']#i', $html, $m)) {
    return html_entity_decode($m[1], ENT_QUOTES);
  }
  return null;
}

// Достаёт JSON-LD (schema.org VideoObject) — часть площадок (Rutube, Coub, реже VK) отдают
// его ботам даже тогда, когда og-теги урезаны/отсутствуют.
function video_extract_jsonld(string $html): array {
  $out = ['title' => null, 'description' => null, 'thumbnail' => null];
  if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
    return $out;
  }
  foreach ($matches[1] as $block) {
    $data = json_decode(trim($block), true);
    if (!is_array($data)) continue;
    $items = isset($data[0]) ? $data : [$data];
    foreach ($items as $item) {
      $type = $item['@type'] ?? '';
      if (is_array($type)) $type = implode(',', $type);
      if (stripos((string)$type, 'Video') === false) continue;
      $out['title']       = $item['name'] ?? $out['title'];
      $out['description'] = $item['description'] ?? $out['description'];
      $thumb = $item['thumbnailUrl'] ?? null;
      if (is_array($thumb)) $thumb = $thumb[0] ?? null;
      $out['thumbnail'] = $thumb ?? $out['thumbnail'];
      if ($out['title']) return $out;
    }
  }
  return $out;
}

function video_extract_og_tags(string $html): array {
  $title = video_meta_tag($html, 'property', 'og:title')
        ?? video_meta_tag($html, 'name', 'twitter:title');
  $description = video_meta_tag($html, 'property', 'og:description')
              ?? video_meta_tag($html, 'name', 'twitter:description')
              ?? video_meta_tag($html, 'name', 'description');
  $thumbnail = video_meta_tag($html, 'property', 'og:image')
            ?? video_meta_tag($html, 'name', 'twitter:image');
  $keywords = video_meta_tag($html, 'name', 'keywords');

  $ogTags = [];
  if (preg_match_all('#<meta[^>]+property=["\']og:video:tag["\'][^>]+content=["\']([^"\']*)["\']#i', $html, $m)) {
    $ogTags = array_map(fn($t) => html_entity_decode($t, ENT_QUOTES), $m[1]);
  }

  if (!$title || !$description || !$thumbnail) {
    $jsonld = video_extract_jsonld($html);
    $title       = $title ?: $jsonld['title'];
    $description = $description ?: $jsonld['description'];
    $thumbnail   = $thumbnail ?: $jsonld['thumbnail'];
  }

  if (!$title && preg_match('#<title>(.*?)</title>#is', $html, $m)) {
    $title = trim(html_entity_decode($m[1], ENT_QUOTES));
  }

  $tagsList = $ogTags;
  if ($keywords) $tagsList = array_merge($tagsList, array_map('trim', explode(',', $keywords)));

  return [
    'title'       => $title,
    'description' => $description,
    'thumbnail'   => $thumbnail,
    'keywords'    => $tagsList ? implode(', ', array_slice(array_unique(array_filter($tagsList)), 0, 10)) : '',
  ];
}

// Основная функция автозаполнения. НИКОГДА не бросает исключение наружу —
// при сбое сети/парсинга просто возвращает пустые поля, и пользователь
// дозаполняет название/описание вручную на форме импорта.
function fetch_video_meta(string $url, string $platform): array {
  $result = ['title' => '', 'description' => '', 'thumbnail_url' => null, 'tags' => '', 'meta_source' => 'none'];

  $oembedUrl = video_oembed_url($platform, $url);
  if ($oembedUrl) {
    $raw = video_http_get($oembedUrl); // уже сам идёт через NETWORK_PROXY_URL, если задан
    if ($raw) {
      $json = json_decode($raw, true);
      if (is_array($json) && !empty($json['title'])) {
        $result['title']         = (string)$json['title'];
        $result['thumbnail_url'] = $json['thumbnail_url'] ?? null;
        $result['description']   = (string)($json['author_name'] ?? '');
        $result['meta_source']   = 'oembed';
        return $result;
      }
    }
  }

  foreach (video_scrape_candidates($platform, $url) as $candidateUrl) {
    $html = video_fetch_with_fallback($candidateUrl);
    if (!$html) continue;
    $og = video_extract_og_tags($html);
    if (!empty($og['title'])) {
      $result['title']         = $og['title'];
      $result['description']   = $og['description'] ?? '';
      $result['thumbnail_url'] = $og['thumbnail'] ?? null;
      $result['tags']          = $og['keywords'] ?? '';
      $result['meta_source']   = 'opengraph';
      return $result;
    }
  }

  // Dropbox / без OG — имя файла
  if ($result['title'] === '' && $platform === 'dropbox') {
    $path = urldecode((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    $base = basename($path);
    if ($base && $base !== 'home') {
      $result['title'] = trim(str_replace(['+', '_'], ' ', preg_replace('/\.[a-z0-9]{2,5}$/i', '', $base)));
      $result['meta_source'] = 'filename';
    }
  }
  if ($result['title'] === '' && $platform === 'instagram') {
    $result['title'] = 'Публикация Instagram';
    $result['meta_source'] = 'default';
  }
  if ($result['tags'] === '' && $result['title'] !== '') {
    $result['tags'] = guess_video_tags($result['title'], $result['description']);
  }
  return $result;
}

function guess_video_tags(string $title, string $description): string {
  $stopwords = ['для','это','как','что','видео','смотреть','онлайн','the','and','for','with'];
  $text = mb_strtolower($title . ' ' . $description);
  preg_match_all('/[а-яa-z0-9]{4,}/u', $text, $m);
  $words = array_diff(array_unique($m[0] ?? []), $stopwords);
  return implode(', ', array_slice($words, 0, 8));
}

// Общий рендер плеера — используется и на самой странице просмотра (video.php), и в
// предпросмотре ПЕРЕД публикацией (video_import.php, channel_manage.php). Одна функция,
// а не две похожие копии в разных местах — иначе предпросмотр рано или поздно разойдётся
// с тем, что реально видят зрители, и перестанет быть надёжным способом отсеивать
// мусорные/битые ссылки до публикации.
function render_player_embed(string $platform, string $embedUrl, string $sourceUrl, string $domId = 'previewPlayer'): void {
  ?>
  <div class="player-wrap" style="position:relative;padding-top:56.25%;background:#000;border-radius:10px;overflow:hidden">
    <?php if ($platform === 'mp4' || $platform === 'dropbox'): ?>
      <video src="<?= e($embedUrl) ?>" controls style="position:absolute;top:0;left:0;width:100%;height:100%"></video>
    <?php elseif ($platform === 'm3u8'): ?>
      <video id="<?= e($domId) ?>" controls style="position:absolute;top:0;left:0;width:100%;height:100%"></video>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js"></script>
      <script>
        (function () {
          var src = <?= json_encode($embedUrl) ?>;
          var v = document.getElementById(<?= json_encode($domId) ?>);
          if (window.Hls && Hls.isSupported()) { var hls = new Hls(); hls.loadSource(src); hls.attachMedia(v); }
          else { v.src = src; }
        })();
      </script>
    <?php elseif ($platform === 'tiktok'):
      $tiktokVideoId = null;
      if (preg_match('#/video/(\d+)#', $sourceUrl, $tm)) { $tiktokVideoId = $tm[1]; }
    ?>
      <blockquote class="tiktok-embed" cite="<?= e($sourceUrl) ?>"<?= $tiktokVideoId ? ' data-video-id="' . e($tiktokVideoId) . '"' : '' ?> style="max-width:100%">
        <a href="<?= e($sourceUrl) ?>">TikTok video</a>
      </blockquote>
      <script async src="https://www.tiktok.com/embed.js"></script>
    <?php elseif ($platform === 'twitter'): ?>
      <blockquote class="twitter-tweet"><a href="<?= e($sourceUrl) ?>"></a></blockquote>
      <script async src="https://platform.twitter.com/widgets.js"></script>
    <?php elseif ($platform === 'reddit'): ?>
      <blockquote class="reddit-embed-bq" style="height:100%">
        <a href="<?= e($sourceUrl) ?>">Reddit post</a>
      </blockquote>
      <script async src="https://embed.reddit.com/widgets.js"></script>
    <?php else: ?>
      <iframe src="<?= e($embedUrl) ?>" allowfullscreen loading="lazy"
        style="position:absolute;top:0;left:0;width:100%;height:100%;border:0"></iframe>
    <?php endif; ?>
  </div>
  <?php
}
