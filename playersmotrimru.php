<?php
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id && isset($_GET['url'])) {
    preg_match('/(\d{5,})/', $_GET['url'], $m);
    $id = $m[1] ?? 0;
}

if ($id == 0) {
    die('<h1 style="text-align:center;margin:100px">Добавь ?id=6049040</h1>');
}

$player = "https://player.smotrim.ru/iframe/video/id/{$id}/";
$original = "https://smotrim.ru/video/{$id}";

// Более агрессивный парсинг
$opts = [
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
];
$context = stream_context_create($opts);
$html = @file_get_contents($original, false, $context) ?: '';

$title = "Смотрим — Видео {$id}";
$desc = "Официальный контент платформы Смотрим";
$image = "https://smotrim.ru/favicon.ico";

if ($html) {
    // Title
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(str_replace([' — Смотрим', 'Смотрим — '], '', $m[1]));
    }
    // Description
    if (preg_match('/name="description" content="(.*?)"/is', $html, $m)) {
        $desc = trim($m[1]);
    }
    // OG Image — несколько вариантов
    if (preg_match('/property="og:image" content="(https[^"]+)"/is', $html, $m)) {
        $image = $m[1];
    } elseif (preg_match('/content="(https[^"]+\.(jpg|jpeg|png|webp))"/is', $html, $m)) {
        $image = $m[1];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=htmlspecialchars($title)?></title>
    <meta name="description" content="<?=htmlspecialchars($desc)?>">
    
    <meta property="og:title" content="<?=htmlspecialchars($title)?>">
    <meta property="og:description" content="<?=htmlspecialchars($desc)?>">
    <meta property="og:image" content="<?=htmlspecialchars($image)?>">
    <meta property="og:url" content="https://<?=htmlspecialchars($_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'])?>">
    <meta property="og:type" content="video.other">
    <meta property="twitter:card" content="summary_large_image">
</head>
<body style="margin:0;background:#0f172a;color:#eee;font-family:Arial,sans-serif">

    
    <div style="position:relative;padding-bottom:56.25%;height:0;background:#000">
        <iframe style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" 
                src="<?=htmlspecialchars($player)?>" 
                allowfullscreen allow="autoplay;fullscreen"></iframe>
    </div>
    
    <div style="padding:25px;max-width:900px;margin:0 auto">
        <p><?=htmlspecialchars($desc)?></p>
        <p><a href="<?=htmlspecialchars($original)?>" target="_blank" style="color:#60a5fa">Оригинал на smotrim.ru</a></p>
    </div>
</body>
</html>