<?php
// Включите отображение ошибок для отладки (при необходимости)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php'; // для функции e()

$base = defined('SITE_URL') ? SITE_URL : 'https://streamlive.freedev.app';
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';

$pdo = db();
$items = [];

// 1. Ресурсы (как в rss_resources.php)
$stmt = $pdo->prepare("SELECT * FROM resources WHERE status = 'published' ORDER BY id DESC LIMIT 50");
$stmt->execute();
$resources = $stmt->fetchAll();
$emojis = ['📦','🆕','📢','💻','🔧','🛠️','📁','🎯','⚡','🚀','🔥','💡','📚','🖥️','📀','🎮','📱','🔗','📎','⭐','🌟','✨','💾','🖱️','⌨️','📂','🔍','🧩','🛡️','📌'];

foreach ($resources as $r) {
    $emoji = $emojis[array_rand($emojis)];
    $desc = $r['summary'] ?? $r['readme'] ?? '';
    $desc = mb_substr($desc, 0, 60);
    if (mb_strlen($desc) == 60) $desc .= '…';
    $description = $desc . ' Скачать на ' . $siteName . ' Без вирусов Бесплатно без СМС';
    $img = !empty($r['screenshot']) ? $r['screenshot'] : null;
    $items[] = [
        'title'       => $emoji . ' НОВЫЙ РЕСУРС: ' . $r['title'],
        'link'        => $base . '/resource.php?slug=' . urlencode($r['slug']),
        'description' => $description,
        'pub_date'    => $r['created_at'],
        'enclosure'   => $img ? ['url' => $img, 'type' => 'image/jpeg', 'length' => 0] : null,
    ];
}

// 2. Видео (как в rss_videos.php)
$stmt = $pdo->prepare("SELECT * FROM videos WHERE status = 'published' ORDER BY created_at DESC LIMIT 50");
$stmt->execute();
$videos = $stmt->fetchAll();
foreach ($videos as $v) {
    $desc = $v['description'] ?? '';
    $desc = mb_substr($desc, 0, 100);
    if (mb_strlen($desc) == 100) $desc .= '…';
    $description = $desc . ' Смотреть на ' . $siteName;
    $img = !empty($v['thumbnail_url']) ? $v['thumbnail_url'] : null;
    $items[] = [
        'title'       => 'Смотреть Новые видео без регистрации без SMS Опубликованное Пользователем (' . $v['title'] . ') Смотреть на ' . $siteName,
        'link'        => $base . '/video.php?slug=' . urlencode($v['slug']),
        'description' => $description,
        'pub_date'    => $v['created_at'],
        'enclosure'   => $img ? ['url' => $img, 'type' => 'image/jpeg', 'length' => 0] : null,
    ];
}

// 3. OAuth-сервисы (как в rss_services.php)
$oauth = $pdo->query(
    "SELECT oa.*, u.username 
     FROM oauth_apps oa 
     JOIN users u ON u.id = oa.owner_id 
     WHERE oa.is_public_service = 1 AND oa.service_url IS NOT NULL 
     ORDER BY oa.id DESC"
)->fetchAll();
foreach ($oauth as $s) {
    $desc = $s['description'] ?? 'Сервис от ' . $s['username'];
    $desc = mb_substr($desc, 0, 60);
    if (mb_strlen($desc) == 60) $desc .= '…';
    $description = $desc . ' Исследовать в ' . $siteName;
    $img = !empty($s['logo_url']) ? $s['logo_url'] : null;
    $items[] = [
        'title'       => 'Добавлен новый пользовательский сервис: ' . $s['name'],
        'link'        => $base . '/oauth2/authorize.php?client_id=' . urlencode($s['client_id']),
        'description' => $description,
        'pub_date'    => $s['created_at'],
        'enclosure'   => $img ? ['url' => $img, 'type' => 'image/png', 'length' => 0] : null,
    ];
}

// 4. Задеплоенные сервисы (как в rss_services.php)
$deployed = $pdo->query(
    "SELECT ds.*, u.username 
     FROM deployed_services ds 
     JOIN users u ON u.id = ds.user_id 
     WHERE ds.is_public = 1 AND ds.status = 'live' 
     ORDER BY ds.id DESC"
)->fetchAll();
foreach ($deployed as $s) {
    $desc = $s['description'] ?? 'Сервис от ' . $s['username'];
    $desc = mb_substr($desc, 0, 60);
    if (mb_strlen($desc) == 60) $desc .= '…';
    $description = $desc . ' Исследовать в ' . $siteName;
    $img = !empty($s['logo_url']) ? $s['logo_url'] : (!empty($s['screenshot']) ? $s['screenshot'] : null);
    $items[] = [
        'title'       => 'Задеплоен новый проект: ' . $s['name'],
        'link'        => $base . '/s.php?slug=' . urlencode($s['slug']),
        'description' => $description,
        'pub_date'    => $s['created_at'],
        'enclosure'   => $img ? ['url' => $img, 'type' => 'image/png', 'length' => 0] : null,
    ];
}

// 5. Каналы (ТВ и радио) – как в rss_channels.php, но объединяем оба типа
$channels = $pdo->query("SELECT * FROM channels WHERE status = 'approved' ORDER BY created_at DESC LIMIT 50")->fetchAll();
foreach ($channels as $c) {
    $typeLabel = ($c['type'] == 'radio') ? 'Радио' : 'ТВ';
    $template = "Смотрим в хорошем качестве HD Без VPN В России с провайдера Ростелеком и др - {$c['title']} (HD) Смотреть в {$siteName}";
    $description = mb_substr($template, 0, 100);
    if (mb_strlen($template) > 100) $description .= '…';
    $img = !empty($c['logo_url']) ? $c['logo_url'] : null;
    $items[] = [
        'title'       => $c['title'],
        'link'        => $base . '/channel-pc.php?slug=' . urlencode($c['slug']),
        'description' => $description,
        'pub_date'    => $c['created_at'],
        'enclosure'   => $img ? ['url' => $img, 'type' => 'image/png', 'length' => 0] : null,
    ];
}

// 6. Форум (как в rss_forum.php, но с avatar)
$forum = $pdo->query(
    "SELECT t.*, u.username, u.avatar,
        (SELECT message FROM forum_posts WHERE thread_id = t.id AND is_deleted = 0 ORDER BY created_at ASC LIMIT 1) AS first_post_message
     FROM forum_threads t
     JOIN users u ON u.id = t.user_id
     WHERE t.is_deleted = 0
     ORDER BY t.id DESC LIMIT 100"
)->fetchAll();
foreach ($forum as $t) {
    $raw = $t['first_post_message'] ?? $t['title'];
    $short = mb_substr($raw, 0, 100);
    if (mb_strlen($raw) > 100) $short .= '…';
    $description = $short . ' Читать на ' . $siteName;
    $img = !empty($t['avatar']) ? $t['avatar'] : null;
    $items[] = [
        'title'       => $t['title'],
        'link'        => $base . '/forum_thread.php?id=' . (int)$t['id'],
        'description' => $description,
        'pub_date'    => $t['created_at'],
        'enclosure'   => $img ? ['url' => $img, 'type' => 'image/jpeg', 'length' => 0] : null,
    ];
}

// Сортировка по дате (новые сверху)
usort($items, function($a, $b) {
    return strtotime($b['pub_date']) - strtotime($a['pub_date']);
});
$items = array_slice($items, 0, 100); // ограничиваем 100 записями

// Вывод RSS
header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
<channel>
    <title><?= e($siteName) ?> — Все новости</title>
    <link><?= e($base) ?></link>
    <description>Свежие ресурсы, видео, сервисы, каналы и форум на <?= e($siteName) ?></description>
    <language>ru</language>
    <lastBuildDate><?= date('r') ?></lastBuildDate>
    <?php foreach ($items as $item): ?>
    <item>
        <title><?= e($item['title']) ?></title>
        <link><?= e($item['link']) ?></link>
        <guid isPermaLink="true"><?= e($item['link']) ?></guid>
        <pubDate><?= date('r', strtotime($item['pub_date'])) ?></pubDate>
        <description><![CDATA[<?= $item['description'] ?>]]></description>
        <?php if (!empty($item['enclosure'])): ?>
        <enclosure url="<?= e($item['enclosure']['url']) ?>" type="<?= e($item['enclosure']['type']) ?>" length="<?= (int)$item['enclosure']['length'] ?>" />
        <?php endif; ?>
    </item>
    <?php endforeach; ?>
</channel>
</rss>