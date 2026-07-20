<?php
/**
 * embed.php — встраиваемый плеер для StreamTok
 * Поддерживает управление через postMessage (play, pause, mute, unmute)
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$slug = $_GET['slug'] ?? '';
$autoplay = isset($_GET['autoplay']) ? (int)$_GET['autoplay'] : 0;
$muted = isset($_GET['mute']) ? (int)$_GET['mute'] : 1;

$stmt = db()->prepare("SELECT * FROM channels WHERE slug = ? AND status = 'approved'");
$stmt->execute([$slug]);
$channel = $stmt->fetch();

if (!$channel) {
    http_response_code(404);
    echo 'Канал недоступен';
    exit;
}

$__user = current_user();
if ($__user && is_user_banned_on_channel($channel['id'], $__user['id'])) {
    http_response_code(403);
    echo 'Доступ запрещён';
    exit;
}

db()->prepare('UPDATE channels SET views = views + 1 WHERE id = ?')->execute([$channel['id']]);
$activeSource = resolve_active_source($channel);

$autoplayAttr = $autoplay ? 'autoplay' : '';
$mutedAttr = $muted ? 'muted' : '';
$playsinlineAttr = 'playsinline webkit-playsinline';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($channel['title']) ?></title>
    <style>
        html, body { margin: 0; padding: 0; background: #000; height: 100%; overflow: hidden; }
        .player-wrap { width: 100%; height: 100%; position: relative; background: #000; display: flex; align-items: center; justify-content: center; }
        .player-wrap iframe, .player-wrap video, .player-wrap img {
            width: 100%; height: 100%; border: none; object-fit: contain;
        }
        .player-logo {
            position: absolute; bottom: 10px; right: 10px;
            width: 32px; height: 32px; border-radius: 8px;
            opacity: .85; z-index: 5; pointer-events: none;
            object-fit: contain;
        }
        .offline-text {
            color: #888; font-size: 14px; font-family: sans-serif;
        }
    </style>
</head>
<body>
<div class="player-wrap" id="playerWrap">
    <?php if (!$activeSource): ?>
        <div class="offline-text">Сейчас нет эфира</div>
    <?php elseif ($activeSource['type'] === 'mp4'): ?>
        <video <?= $autoplayAttr ?> <?= $mutedAttr ?> <?= $playsinlineAttr ?> loop playsinline id="playerVideo">
            <source src="<?= htmlspecialchars($activeSource['url']) ?>" type="video/mp4">
        </video>
    <?php elseif ($activeSource['type'] === 'hls' || $activeSource['type'] === 'm3u8'): ?>
        <video <?= $autoplayAttr ?> <?= $mutedAttr ?> <?= $playsinlineAttr ?> loop playsinline id="playerVideo">
            <source src="<?= htmlspecialchars($activeSource['url']) ?>" type="application/x-mpegURL">
        </video>
    <?php elseif ($activeSource['type'] === 'iframe'): ?>
        <iframe src="<?= htmlspecialchars($activeSource['url']) ?>" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen id="playerIframe"></iframe>
    <?php elseif ($activeSource['type'] === 'image'): ?>
        <img src="<?= htmlspecialchars($activeSource['url']) ?>" alt="" id="playerImage">
    <?php else: ?>
        <iframe src="<?= htmlspecialchars($activeSource['url']) ?>" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen id="playerIframe"></iframe>
    <?php endif; ?>
    <?php if ($channel['logo_url']): ?>
        <img class="player-logo" src="<?= htmlspecialchars($channel['logo_url']) ?>" alt="">
    <?php endif; ?>
</div>

<script>
    // Получаем элементы
    const video = document.getElementById('playerVideo');
    const iframe = document.getElementById('playerIframe');
    const img = document.getElementById('playerImage');

    // Слушаем сообщения от родительского окна
    window.addEventListener('message', function(event) {
        const data = event.data;
        if (!data || data.type !== 'player_control') return;

        // Управление видео
        if (video) {
            if (data.action === 'play') {
                video.muted = false;
                video.play().catch(() => {});
            } else if (data.action === 'pause') {
                video.pause();
            } else if (data.action === 'mute') {
                video.muted = true;
            } else if (data.action === 'unmute') {
                video.muted = false;
            }
        }

        // Для iframe мы не можем управлять внутренним плеером, поэтому просто игнорируем
        // (но можно было бы перезагрузить src, но мы отказываемся от перезагрузки)
        // Поэтому оставляем как есть.
    });

    // Если есть параметр autoplay и video, запускаем сразу
    <?php if ($autoplay && $activeSource && in_array($activeSource['type'], ['mp4', 'hls', 'm3u8'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        if (video) {
            video.muted = <?= $muted ? 'true' : 'false' ?>;
            video.play().catch(() => {});
        }
    });
    <?php endif; ?>
</script>
</body>
</html>