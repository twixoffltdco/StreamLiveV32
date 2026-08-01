<?php
// embed.php – универсальный плеер для любого сайта
$url = isset($_GET['url']) ? trim($_GET['url']) : '';
if (!$url) {
    die('<h1 style="text-align:center;margin:100px;color:#eee">Укажи ссылку на страницу с видео: ?url=https://example.com/video</h1>');
}

// Загрузка страницы
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$html = curl_exec($ch);
curl_close($ch);

if (!$html) {
    die('Не удалось загрузить страницу');
}

// Функция для преобразования относительных ссылок в абсолютные
function to_absolute($rel, $base_url) {
    if (strpos($rel, 'http') === 0) return $rel;
    if (strpos($rel, '//') === 0) return 'https:' . $rel;
    if (strpos($rel, '/') === 0) {
        $parsed = parse_url($base_url);
        return $parsed['scheme'] . '://' . $parsed['host'] . $rel;
    }
    $parsed = parse_url($base_url);
    $path = $parsed['path'];
    $dir = dirname($path);
    if ($dir !== '/') $dir .= '/';
    return $parsed['scheme'] . '://' . $parsed['host'] . $dir . $rel;
}

$base_url = $url;
$parsed = parse_url($base_url);
$host = $parsed['host'];

// ====== Извлечение метаданных ======
$title = '';
$description = '';
$image = '';

// Title
if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
    $title = $m[1];
} elseif (preg_match('/<meta\s+name=["\']twitter:title["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
    $title = $m[1];
} elseif (preg_match('/<title>(.*?)<\/title>/i', $html, $m)) {
    $title = trim($m[1]);
}

// Description
if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
    $description = $m[1];
} elseif (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
    $description = $m[1];
} elseif (preg_match('/<meta\s+name=["\']twitter:description["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
    $description = $m[1];
}

// Image
if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
    $image = $m[1];
} elseif (preg_match('/<meta\s+property=["\']og:image:url["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
    $image = $m[1];
} elseif (preg_match('/<link\s+rel=["\']image_src["\']\s+href=["\']([^"\']*)["\']/i', $html, $m)) {
    $image = $m[1];
}

if ($image) $image = to_absolute($image, $base_url);
if (!$title) $title = 'Видео';
if (!$description) $description = 'Видео с сайта ' . $host;
if (!$image) $image = 'https://via.placeholder.com/800x450/0f172a/ffffff?text=Video';

// ====== Поиск плеера ======
$iframe_url = '';
$video_url = '';
$hls_url = '';

// 1. Ищем iframe
preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
if (!empty($matches[1])) {
    foreach ($matches[1] as $src) {
        $src = to_absolute($src, $base_url);
        // Предпочтение iframe с ключевыми словами в src
        if (preg_match('/(player|embed|video|rutube|youtube|youtu\.be|vimeo|vk\.com|smotrim|1tv|ntv|tvzvezda|matchtv|ok\.ru)/i', $src)) {
            $iframe_url = $src;
            break;
        }
    }
    if (!$iframe_url) {
        $iframe_url = to_absolute($matches[1][0], $base_url);
    }
}

// 2. Ищем video
if (!$iframe_url) {
    if (preg_match('/<video[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
        $video_url = to_absolute($m[1], $base_url);
    } else {
        preg_match_all('/<source[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $src) {
                $src = to_absolute($src, $base_url);
                if (preg_match('/\.m3u8$/i', $src)) {
                    $hls_url = $src;
                } else {
                    $video_url = $src;
                }
            }
        }
    }
}

// 3. Ищем HLS в JSON или атрибутах
if (!$iframe_url && !$video_url && !$hls_url) {
    if (preg_match('/data-(?:video|hls|src)["\']?\s*=\s*["\']([^"\']+\.m3u8)["\']/i', $html, $m)) {
        $hls_url = to_absolute($m[1], $base_url);
    } elseif (preg_match('/"video"\s*:\s*"([^"]+\.m3u8)"/i', $html, $m)) {
        $hls_url = to_absolute($m[1], $base_url);
    } elseif (preg_match('/"hls"\s*:\s*"([^"]+\.m3u8)"/i', $html, $m)) {
        $hls_url = to_absolute($m[1], $base_url);
    } elseif (preg_match('/"src"\s*:\s*"([^"]+\.m3u8)"/i', $html, $m)) {
        $hls_url = to_absolute($m[1], $base_url);
    } elseif (preg_match('/"url"\s*:\s*"([^"]+\.m3u8)"/i', $html, $m)) {
        $hls_url = to_absolute($m[1], $base_url);
    }
}

// 4. Если ничего нет – ищем mp4/webm/ogg
if (!$iframe_url && !$video_url && !$hls_url) {
    preg_match_all('/(https?:\/\/[^\s"\']+\.(?:mp4|webm|ogg))/i', $html, $matches);
    if (!empty($matches[1])) {
        $video_url = $matches[1][0];
    }
}

// 5. Если есть og:video
if (!$iframe_url && !$video_url && !$hls_url) {
    if (preg_match('/<meta\s+property=["\']og:video["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $maybe = to_absolute($m[1], $base_url);
        if (preg_match('/\.m3u8$/i', $maybe)) {
            $hls_url = $maybe;
        } else {
            $video_url = $maybe;
        }
    }
}

// Определяем тип плеера
$player_type = '';
$player_src = '';
if ($iframe_url) {
    $player_type = 'iframe';
    $player_src = $iframe_url;
} elseif ($hls_url) {
    $player_type = 'hls';
    $player_src = $hls_url;
} elseif ($video_url) {
    $player_type = 'video';
    $player_src = $video_url;
} else {
    die('Не удалось найти плеер на странице');
}

// Ручное переопределение
if (!empty($_GET['title'])) $title = trim($_GET['title']);
if (!empty($_GET['description'])) $description = trim($_GET['description']);
if (!empty($_GET['image'])) $image = trim($_GET['image']);
if (!empty($_GET['iframe'])) { $player_type = 'iframe'; $player_src = trim($_GET['iframe']); }
if (!empty($_GET['hls'])) { $player_type = 'hls'; $player_src = trim($_GET['hls']); }
if (!empty($_GET['video'])) { $player_type = 'video'; $player_src = trim($_GET['video']); }

$embed = isset($_GET['embed']) ? 1 : 0;
$current_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$embed_url = $current_url . '&embed=1';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=htmlspecialchars($title)?></title>
    <meta name="description" content="<?=htmlspecialchars($description)?>">
    <meta property="og:title" content="<?=htmlspecialchars($title)?>">
    <meta property="og:description" content="<?=htmlspecialchars($description)?>">
    <meta property="og:image" content="<?=htmlspecialchars($image)?>">
    <meta property="og:image:secure_url" content="<?=htmlspecialchars($image)?>">
    <meta property="og:image:width" content="800">
    <meta property="og:image:height" content="450">
    <meta property="og:url" content="<?=htmlspecialchars($current_url)?>">
    <meta property="og:type" content="video.other">
    <meta property="og:site_name" content="Универсальный плеер">
    <meta property="og:video" content="<?=htmlspecialchars($embed_url)?>">
    <meta property="og:video:type" content="text/html">
    <meta property="og:video:width" content="640">
    <meta property="og:video:height" content="360">
    <link rel="image_src" href="<?=htmlspecialchars($image)?>">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#0f172a; color:#e2e8f0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; display:flex; flex-direction:column; min-height:100vh; }
        .player-wrapper { position:relative; padding-bottom:56.25%; height:0; background:#000; }
        .player-wrapper iframe, .player-wrapper video { position:absolute; top:0; left:0; width:100%; height:100%; border:0; background:#000; }
        .content { max-width:900px; margin:0 auto; padding:20px 25px; flex:1; width:100%; }
        .content h1 { font-size:1.5rem; margin-bottom:0.5rem; font-weight:500; }
        .content .desc { color:#94a3b8; margin-bottom:1.2rem; line-height:1.5; }
        .actions { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-top:10px; }
        .btn { display:inline-flex; align-items:center; gap:8px; background:#1e293b; color:#e2e8f0; padding:10px 20px; border-radius:30px; text-decoration:none; font-size:0.9rem; transition:background 0.2s; border:1px solid #334155; cursor:pointer; }
        .btn:hover { background:#334155; }
        .btn-vk { background:#2787F5; border-color:#2787F5; color:#fff; }
        .btn-vk:hover { background:#1a6ecf; }
        .btn-original { background:transparent; border-color:#475569; }
        .btn-original:hover { background:#1e293b; }
        .footer { text-align:center; padding:20px; color:#475569; font-size:0.8rem; border-top:1px solid #1e293b; }
        code { background:#1e293b; padding:2px 8px; border-radius:4px; }
        <?php if ($embed): ?>
        .content, .footer { display:none !important; }
        <?php endif; ?>
    </style>
</head>
<body>
    <div class="player-wrapper">
        <?php if ($player_type == 'iframe'): ?>
            <iframe src="<?=htmlspecialchars($player_src)?>" allowfullscreen allow="autoplay;fullscreen;encrypted-media"></iframe>
        <?php elseif ($player_type == 'hls'): ?>
            <video id="hls-video" controls playsinline preload="metadata"></video>
        <?php elseif ($player_type == 'video'): ?>
            <video controls playsinline preload="metadata">
                <source src="<?=htmlspecialchars($player_src)?>" type="video/mp4">
                Ваш браузер не поддерживает видео.
            </video>
        <?php endif; ?>
    </div>
    <div class="content">
        <h1><?=htmlspecialchars($title)?></h1>
        <div class="desc"><?=htmlspecialchars($description)?></div>
        <div class="actions">
            <a href="https://vk.com/share.php?url=<?=urlencode($current_url)?>&title=<?=urlencode($title)?>&description=<?=urlencode($description)?>&image=<?=urlencode($image)?>" target="_blank" class="btn btn-vk">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M21.64 5.16c.25-.44.28-1.06-.75-1.06h-2.48c-.7 0-1.02.37-1.2.77 0 0-1.42 3.44-3.42 5.68-.65.65-.95.85-1.3.85-.25 0-.62-.2-.62-.78V5.16c0-.66.19-1.06-.83-1.06h-3.9c-.46 0-.75.34-.75.67 0 .7 1.06.86 1.17 2.82v4.24c0 .94-.17 1.11-.54 1.11-.95 0-3.26-3.48-4.63-7.45-.27-.78-.54-1.09-1.24-1.09H2.2c-.7 0-.84.37-.84.77 0 .72.99 4.3 4.63 9.05 2.42 3.49 5.83 5.36 8.94 5.36 1.87 0 2.1-.42 2.1-1.14v-2.63c0-.7.15-.84.65-.84.37 0 1 .18 2.48 1.32 1.69 1.69 1.96 2.44 2.9 2.44h2.48c.7 0 1.05-.37.84-1.06-.22-.68-1.03-1.67-2.1-2.84-.65-.77-1.62-1.6-1.92-2.01-.4-.5-.28-.73 0-1.18 0 0 3.38-4.76 3.74-6.37z"/></svg>
                Поделиться ВК
            </a>
            <a href="<?=htmlspecialchars($url)?>" target="_blank" class="btn btn-original">Открыть оригинал →</a>
            <button class="btn" onclick="copyEmbed()" id="copyBtn">📋 Копировать embed-код</button>
        </div>
    </div>
    <div class="footer">Универсальный плеер • Для вставки используй <code>?embed=1</code></div>

    <?php if ($player_type == 'hls'): ?>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script>
        (function() {
            var video = document.getElementById('hls-video');
            var hlsUrl = '<?=addslashes($player_src)?>';
            if (Hls.isSupported()) {
                var hls = new Hls({ enableWorker: true, lowLatencyMode: true });
                hls.loadSource(hlsUrl);
                hls.attachMedia(video);
                hls.on(Hls.Events.MANIFEST_PARSED, function() {
                    video.play().catch(function(e) {});
                });
                hls.on(Hls.Events.ERROR, function(event, data) {
                    if (data.fatal) console.error('HLS error:', data);
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = hlsUrl;
                video.play().catch(function(e) {});
            } else {
                video.parentNode.innerHTML = '<div style="color:red;text-align:center;padding:20px;">Ваш браузер не поддерживает HLS-потоки</div>';
            }
        })();
    </script>
    <?php endif; ?>

    <script>
        function copyEmbed() {
            var url = window.location.href.split('?')[0] + '?url=<?=urlencode($url)?>&embed=1';
            var code = '<iframe src="' + url + '" width="640" height="360" frameborder="0" allowfullscreen allow="autoplay;fullscreen"></iframe>';
            navigator.clipboard.writeText(code).then(function() {
                var btn = document.getElementById('copyBtn');
                btn.textContent = '✅ Скопировано!';
                setTimeout(function() { btn.textContent = '📋 Копировать embed-код'; }, 3000);
            });
        }
    </script>
</body>
</html>