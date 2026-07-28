<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rss.php';

$base = defined('SITE_URL') ? SITE_URL : 'https://streamlive.freedev.app';
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';

// Большой набор смайликов, подходящих для ресурсов
$emojis = [
    '📦', '🆕', '📢', '💻', '🔧', '🛠️', '📁', '🎯', '⚡', '🚀',
    '🔥', '💡', '📚', '🖥️', '📀', '🎮', '📱', '🔗', '📎', '⭐',
    '🌟', '✨', '💾', '🖱️', '⌨️', '📂', '🔍', '🧩', '🛡️', '📌'
];

$stmt = db()->prepare("SELECT * FROM resources WHERE status = 'published' ORDER BY id DESC LIMIT 50");
$stmt->execute();
$resources = $stmt->fetchAll();

$items = array_map(function ($r) use ($base, $siteName, $emojis) {
    // Случайный смайлик для этого ресурса
    $emoji = $emojis[array_rand($emojis)];

    $desc = $r['summary'] ?: $r['readme'];
    if (mb_strlen($desc) > 60) {
        $description = mb_substr($desc, 0, 60) . '…';
    } else {
        $description = $desc ?: '';
    }
    $description .= ' Скачать на ' . $siteName . ' Без вирусов Бесплатно без СМС';

    return [
        'title'       => $emoji . ' НОВЫЙ РЕСУРС: ' . $r['title'],
        'link'        => $base . '/resource.php?slug=' . urlencode($r['slug']),
        'description' => $description,
        'pub_date'    => $r['created_at'],
    ];
}, $resources);

render_rss_xml(
    $siteName . ' — Новые ресурсы',
    $base . '/resources.php',
    'Новые ресурсы на ' . $siteName,
    $items
);