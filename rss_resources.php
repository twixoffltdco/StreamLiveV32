<?php
require_once __DIR__ . '/includes/resources.php';
header('Content-Type: application/rss+xml; charset=utf-8');
$items = resources_list(false, 50);
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\"><channel><title>" . e(SITE_NAME) . " — ресурсы</title><link>" . e(SITE_URL) . "/resources</link><description>Новые ресурсы</description>";
foreach ($items as $r) echo '<item><title>' . e($r['title']) . '</title><link>' . e(SITE_URL . resource_url($r)) . '</link><description>' . e($r['summary']) . '</description><pubDate>' . date(DATE_RSS, strtotime($r['created_at'])) . '</pubDate></item>';
echo '</channel></rss>';
