<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$base = defined('SITE_URL') ? SITE_URL : 'https://streamlive.freedev.app';
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';

// Последние 50 опубликованных видео
$stmt = db()->prepare("SELECT * FROM videos WHERE status = 'published' ORDER BY created_at DESC LIMIT 50");
$stmt->execute();
$videos = $stmt->fetchAll();

// Заголовки RSS
header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
<channel>
    <title><?= e($siteName) ?> — Новые видео</title>
    <link><?= e($base) ?>/videos.php</link>
    <description>Свежие видео на <?= e($siteName) ?></description>
    <language>ru</language>
    <lastBuildDate><?= date('r') ?></lastBuildDate>
    <?php foreach ($videos as $v): ?>
    <item>
        <title>Смотреть Новые видео без регистрации без SMS Опубликованное Пользователем (<?= e($v['title']) ?>) Смотреть на <?= e($siteName) ?></title>
        <link><?= e($base) ?>/video.php?slug=<?= urlencode($v['slug']) ?></link>
        <guid isPermaLink="true"><?= e($base) ?>/video.php?slug=<?= urlencode($v['slug']) ?></guid>
        <pubDate><?= date('r', strtotime($v['created_at'])) ?></pubDate>
        <description><![CDATA[
            <?php
            $desc = $v['description'] ?? '';
            if (mb_strlen($desc) > 100) {
                echo e(mb_substr($desc, 0, 100)) . '…';
            } else {
                echo e($desc ?: 'Новое видео');
            }
            ?>
        ]]></description>
        <?php if (!empty($v['thumbnail_url'])): ?>
        <enclosure url="<?= e($v['thumbnail_url']) ?>" type="image/jpeg" length="0" />
        <?php endif; ?>
    </item>
    <?php endforeach; ?>
</channel>
</rss>