<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rss.php';

$base = defined('SITE_URL') ? SITE_URL : 'https://streamlive.freedev.app';
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';

// Последние 50 опубликованных видео
$stmt = db()->prepare("SELECT * FROM videos WHERE status = 'published' ORDER BY created_at DESC LIMIT 50");
$stmt->execute();
$videos = $stmt->fetchAll();

$items = array_map(function ($v) use ($base, $siteName) {
    // Заголовок со смайликом и обязательной фразой
    $title = '🎬 Смотреть Новые видео без регистрации без SMS на сайте! - '. $siteName. ': ' . $v['title'];

    // Описание: первые 100 символов из description + суффикс
    $desc = $v['description'] ?? '';
    if (mb_strlen($desc) > 100) {
        $description = mb_substr($desc, 0, 100) . '…';
    } else {
        $description = $desc ?: 'Новое видео';
    }
    $description .= ' Смотреть на ' . $siteName;

    return [
        'title'       => $title,
        'link'        => $base . '/video.php?slug=' . urlencode($v['slug']),
        'description' => $description,
        'pub_date'    => $v['created_at'],
    ];
}, $videos);

// Отдаём RSS-ленту
render_rss_xml(
    $siteName . ' — Новые видео',
    $base . '/videos.php',
    'Свежие видео на ' . $siteName,
    $items
);