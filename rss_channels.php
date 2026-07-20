<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rss.php';

$type = ($_GET['type'] ?? 'tv') === 'radio' ? 'radio' : 'tv';
$base = defined('SITE_URL') ? SITE_URL : 'https://streamlive.freedev.app';
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';

$stmt = db()->prepare("SELECT * FROM channels WHERE status = 'approved' AND type = ? ORDER BY id DESC LIMIT 50");
$stmt->execute([$type]);
$channels = $stmt->fetchAll();

$items = array_map(function ($c) use ($base, $siteName) {
    // Формируем описание по шаблону
    $template = "Смотрим в хорошем качестве HD Без VPN В России с провайдера Ростелеком и др - {$c['title']} (HD) Смотреть в {$siteName}";
    
    // Обрезаем до 100 символов (с учётом многобайтовости)
    if (mb_strlen($template) > 100) {
        $description = mb_substr($template, 0, 100) . '…';
    } else {
        $description = $template;
    }

    return [
        'title'     => $c['title'],
        'link'      => $base . '/channel-pc.php?slug=' . urlencode($c['slug']),
        'description' => $description,
        'pub_date'  => $c['created_at'],
    ];
}, $channels);

$typeLabel = $type === 'radio' ? 'Радио' : 'ТВ';
render_rss_xml(
    (defined('SITE_NAME') ? SITE_NAME : 'StreamLive') . ' — ' . $typeLabel . '-каналы',
    $base . '/catalog.php?type=' . $type,
    'Новые ' . ($type === 'radio' ? 'радиостанции' : 'телеканалы') . ' на ' . (defined('SITE_NAME') ? SITE_NAME : 'StreamLive'),
    $items
);