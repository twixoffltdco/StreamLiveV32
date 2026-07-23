<?php
// ====== Получение ID видео ======
$url = isset($_GET['url']) ? trim($_GET['url']) : '';
$oid = isset($_GET['oid']) ? (int)$_GET['oid'] : null;
$vid = isset($_GET['vid']) ? (int)$_GET['vid'] : null;
$hash = isset($_GET['hash']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['hash']) : '';

if ($url && !$oid) {
    if (preg_match('/video([\-]?\d+)_(\d+)/', $url, $m)) {
        $oid = (int)$m[1];
        $vid = (int)$m[2];
    } elseif (preg_match('/oid=([\-]?\d+)&id=(\d+)(?:&hash=([a-zA-Z0-9_-]+))?/', $url, $m)) {
        $oid = (int)$m[1];
        $vid = (int)$m[2];
        if (!empty($m[3])) $hash = $m[3];
    }
}

if (!$oid || !$vid) {
    die('<h1 style="text-align:center;margin:100px;color:#eee">Укажи ссылку на видео ВК: ?url=https://vk.com/video-123456_789012345</h1>');
}

// ====== Формируем URL плеера ======
$player_url = "https://vk.com/video_ext.php?oid={$oid}&id={$vid}&hd=1";
if ($hash) $player_url .= "&hash={$hash}";
if (isset($_GET['autoplay'])) $player_url .= '&autoplay=1';

// ====== Парсим страницу iframe ВК (вариант 2) ======
$title = "Видео ВКонтакте";
$desc  = "Видео с социальной сети ВКонтакте";
$image = "https://vk.com/favicon.ico";

$opts = [
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
];
$context = stream_context_create($opts);
$html = @file_get_contents($player_url, false, $context) ?: '';

if ($html) {
    // Title
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        $title = trim($m[1]);
        // Убираем суффиксы типа " | ВКонтакте"
        $title = preg_replace('/\s*[|–]\s*ВКонтакте$/i', '', $title);
    }
    // Description
    if (preg_match('/<meta name="description" content="(.*?)"/is', $html, $m)) {
        $desc = trim($m[1]);
    }
    // OG Image
    if (preg_match('/<meta property="og:image" content="(https[^"]+)"/is', $html, $m)) {
        $image = $m[1];
    } elseif (preg_match('/<meta property="og:image:url" content="(https[^"]+)"/is', $html, $m)) {
        $image = $m[1];
    } elseif (preg_match('/background-image:\s*url\(([^)]+)\)/is', $html, $m)) {
        // Иногда обложка в стилях
        $image = trim($m[1], ' "\'');
    }
    // Если ссылка относительная
    if ($image && strpos($image, '//') === 0) {
        $image = 'https:' . $image;
    }
}

// ====== Ручное переопределение через GET ======
if (!empty($_GET['title'])) $title = trim($_GET['title']);
if (!empty($_GET['description'])) $desc = trim($_GET['description']);
if (!empty($_GET['image'])) $image = trim($_GET['image']);

// ====== Режим embed ======
$embed = isset($_GET['embed']) ? 1 : 0;
if ($embed) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"></head>
    <body style="margin:0;background:#000">
        <div style="position:relative;padding-bottom:56.25%;height:0;background:#000">
            <iframe style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" 
                    src="<?=htmlspecialchars($player_url)?>" 
                    allowfullscreen allow="autoplay;fullscreen"></iframe>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ====== Полноценная страница с мета-тегами ======
$current_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$embed_url = $current_url . '&embed=1';
$share_url = $url ?: "https://vk.com/video{$oid}_{$vid}";

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=htmlspecialchars($title)?></title>
    <meta name="description" content="<?=htmlspecialchars($desc)?>">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?=htmlspecialchars($title)?>">
    <meta property="og:description" content="<?=htmlspecialchars($desc)?>">
    <meta property="og:image" content="<?=htmlspecialchars($image)?>">
    <meta property="og:image:secure_url" content="<?=htmlspecialchars($image)?>">
    <meta property="og:image:width" content="800">
    <meta property="og:image:height" content="450">
    <meta property="og:url" content="<?=htmlspecialchars($current_url)?>">
    <meta property="og:type" content="video.other">
    <meta property="og:site_name" content="Видео ВКонтакте">
    <meta property="og:video" content="<?=htmlspecialchars($embed_url)?>">
    <meta property="og:video:type" content="text/html">
    <meta property="og:video:width" content="640">
    <meta property="og:video:height" content="360">
    <link rel="image_src" href="<?=htmlspecialchars($image)?>">
    
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?=htmlspecialchars($title)?>">
    <meta name="twitter:description" content="<?=htmlspecialchars($desc)?>">
    <meta name="twitter:image" content="<?=htmlspecialchars($image)?>">
    
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            background:#0f172a;
            color:#e2e8f0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            display:flex;
            flex-direction:column;
            min-height:100vh;
        }
        .player-wrapper {
            position:relative;
            padding-bottom:56.25%;
            height:0;
            background:#000;
        }
        .player-wrapper iframe {
            position:absolute;
            top:0;
            left:0;
            width:100%;
            height:100%;
            border:0;
        }
        .content {
            max-width:900px;
            margin:0 auto;
            padding:20px 25px;
            flex:1;
            width:100%;
        }
        .content h1 {
            font-size:1.5rem;
            margin-bottom:0.5rem;
            font-weight:500;
        }
        .content .desc {
            color:#94a3b8;
            margin-bottom:1.2rem;
            line-height:1.5;
        }
        .actions {
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            align-items:center;
            margin-top:10px;
        }
        .btn {
            display:inline-flex;
            align-items:center;
            gap:8px;
            background:#1e293b;
            color:#e2e8f0;
            padding:10px 20px;
            border-radius:30px;
            text-decoration:none;
            font-size:0.9rem;
            transition:background 0.2s;
            border:1px solid #334155;
            cursor:pointer;
        }
        .btn:hover { background:#334155; }
        .btn-vk {
            background:#2787F5;
            border-color:#2787F5;
            color:#fff;
        }
        .btn-vk:hover { background:#1a6ecf; }
        .btn-original {
            background:transparent;
            border-color:#475569;
        }
        .btn-original:hover { background:#1e293b; }
        .footer {
            text-align:center;
            padding:20px;
            color:#475569;
            font-size:0.8rem;
            border-top:1px solid #1e293b;
        }
        code {
            background:#1e293b;
            padding:2px 8px;
            border-radius:4px;
        }
    </style>
</head>
<body>
    <div class="player-wrapper">
        <iframe src="<?=htmlspecialchars($player_url)?>" 
                allowfullscreen allow="autoplay;fullscreen"></iframe>
    </div>
    <div class="content">
        <h1><?=htmlspecialchars($title)?></h1>
        <div class="desc"><?=htmlspecialchars($desc)?></div>
        <div class="actions">
            <a href="https://vk.com/share.php?url=<?=urlencode($current_url)?>&title=<?=urlencode($title)?>&description=<?=urlencode($desc)?>&image=<?=urlencode($image)?>"
               target="_blank" class="btn btn-vk">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M21.64 5.16c.25-.44.28-1.06-.75-1.06h-2.48c-.7 0-1.02.37-1.2.77 0 0-1.42 3.44-3.42 5.68-.65.65-.95.85-1.3.85-.25 0-.62-.2-.62-.78V5.16c0-.66.19-1.06-.83-1.06h-3.9c-.46 0-.75.34-.75.67 0 .7 1.06.86 1.17 2.82v4.24c0 .94-.17 1.11-.54 1.11-.95 0-3.26-3.48-4.63-7.45-.27-.78-.54-1.09-1.24-1.09H2.2c-.7 0-.84.37-.84.77 0 .72.99 4.3 4.63 9.05 2.42 3.49 5.83 5.36 8.94 5.36 1.87 0 2.1-.42 2.1-1.14v-2.63c0-.7.15-.84.65-.84.37 0 1 .18 2.48 1.32 1.69 1.69 1.96 2.44 2.9 2.44h2.48c.7 0 1.05-.37.84-1.06-.22-.68-1.03-1.67-2.1-2.84-.65-.77-1.62-1.6-1.92-2.01-.4-.5-.28-.73 0-1.18 0 0 3.38-4.76 3.74-6.37z"/></svg>
                Поделиться ВК
            </a>
            <a href="<?=htmlspecialchars($share_url)?>" target="_blank" class="btn btn-original">
                Открыть в ВК →
            </a>
            <button class="btn" onclick="copyEmbed()" id="copyBtn">📋 Копировать embed-код</button>
        </div>
    </div>
    <div class="footer">
        Плеер в стиле «Смотрим» для видео ВКонтакте • Для вставки используй <code>?embed=1</code>
    </div>
    <script>
        function copyEmbed() {
            const url = window.location.href.split('?')[0] + '?oid=<?=$oid?>&vid=<?=$vid?>&hash=<?=$hash?>&embed=1';
            const code = `<iframe src="${url}" width="640" height="360" frameborder="0" allowfullscreen allow="autoplay;fullscreen"></iframe>`;
            navigator.clipboard.writeText(code).then(() => {
                const btn = document.getElementById('copyBtn');
                btn.textContent = '✅ Скопировано!';
                setTimeout(() => btn.textContent = '📋 Копировать embed-код', 3000);
            });
        }
    </script>
</body>
</html>