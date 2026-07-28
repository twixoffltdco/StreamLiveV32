<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rss.php';
require_once __DIR__ . '/includes/functions.php';

$base = defined('SITE_URL') ? SITE_URL : 'https://streamlive.freedev.app';
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';

// 1. OAuth-сервисы (публичные, с service_url)
$stmtOauth = db()->query(
    "SELECT oa.*, u.username 
     FROM oauth_apps oa 
     JOIN users u ON u.id = oa.owner_id 
     WHERE oa.is_public_service = 1 AND oa.service_url IS NOT NULL 
     ORDER BY oa.id DESC"
);
$oauthServices = $stmtOauth->fetchAll();

// 2. Развёрнутые сервисы (публичные, статус live)
$stmtDeployed = db()->query(
    "SELECT ds.*, u.username 
     FROM deployed_services ds 
     JOIN users u ON u.id = ds.user_id 
     WHERE ds.is_public = 1 AND ds.status = 'live' 
     ORDER BY ds.id DESC"
);
$deployedServices = $stmtDeployed->fetchAll();

// Объединяем в общий массив
$items = [];

foreach ($oauthServices as $s) {
    $items[] = [
        'type'       => 'oauth',
        'name'       => $s['name'],
        'description'=> $s['description'] ?? '',
        'username'   => $s['username'],
        'created_at' => $s['created_at'],
        'client_id'  => $s['client_id'],
        'slug'       => null,
    ];
}

foreach ($deployedServices as $s) {
    $items[] = [
        'type'       => 'deployed',
        'name'       => $s['name'],
        'description'=> $s['description'] ?? '',
        'username'   => $s['username'],
        'created_at' => $s['created_at'],
        'client_id'  => null,
        'slug'       => $s['slug'],
    ];
}

// Сортируем по дате (новые сверху)
usort($items, function ($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Берём последние 50
$items = array_slice($items, 0, 50);

// Формируем RSS-элементы
$rssItems = array_map(function ($item) use ($base, $siteName) {
    if ($item['type'] === 'oauth') {
        $title = 'Добавлен новый пользовательский сервис: ' . $item['name'];
        $link = $base . '/oauth2/authorize.php?client_id=' . urlencode($item['client_id']);
    } else {
        $title = 'Задеплоен новый проект: ' . $item['name'];
        $link = $base . '/s.php?slug=' . urlencode($item['slug']);
    }

    $desc = $item['description'] ?: 'Сервис от ' . $item['username'];
    if (mb_strlen($desc) > 60) {
        $description = mb_substr($desc, 0, 60) . '…';
    } else {
        $description = $desc;
    }
    $description .= ' Исследовать в ' . $siteName;

    return [
        'title'       => $title,
        'link'        => $link,
        'description' => $description,
        'pub_date'    => $item['created_at'],
    ];
}, $items);

// Выводим RSS
render_rss_xml(
    $siteName . ' — Новые сервисы',
    $base . '/services.php',
    'Свежие сервисы и проекты сообщества на ' . $siteName,
    $rssItems
);