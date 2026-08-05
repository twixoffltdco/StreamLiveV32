<?php
/**
 * Единый RSS: видео, каналы, форум, ресурсы, сервисы.
 * VK-совместимый: абсолютные URL, guid, pubDate RFC822, enclosure.
 * ?limit=200 — сколько элементов (по умолчанию 150, макс 300)
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$base = rtrim(defined('SITE_URL') ? SITE_URL : ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$limit = (int)($_GET['limit'] ?? 150);
if ($limit < 10) $limit = 10;
if ($limit > 300) $limit = 300;
$per = max(20, (int)ceil($limit / 5));

function rss_abs(string $url, string $base): string {
  $url = trim($url);
  if ($url === '') return '';
  if (preg_match('#^https?://#i', $url)) return $url;
  return $base . '/' . ltrim($url, '/');
}

function rss_date($v): string {
  $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
  if (!$ts) $ts = time();
  return date('r', $ts);
}

$pdo = db();
$items = [];

// Videos — все published (не только «новые»)
try {
  $st = $pdo->query("SELECT * FROM videos WHERE status = 'published' ORDER BY COALESCE(created_at, id) DESC LIMIT " . (int)$per);
  foreach ($st->fetchAll() ?: [] as $v) {
    $link = $base . '/video.php?slug=' . rawurlencode((string)$v['slug']);
    $thumb = rss_abs((string)($v['thumbnail_url'] ?? ''), $base);
    $items[] = [
      'title' => (string)$v['title'],
      'link' => $link,
      'guid' => $link,
      'description' => mb_substr(strip_tags((string)($v['description'] ?? $v['title'])), 0, 400),
      'pub_date' => $v['created_at'] ?? null,
      'image' => $thumb,
    ];
  }
} catch (Throwable $e) {}

// Channels
try {
  $st = $pdo->query("SELECT * FROM channels WHERE status = 'approved' ORDER BY COALESCE(created_at, id) DESC LIMIT " . (int)$per);
  foreach ($st->fetchAll() ?: [] as $c) {
    $link = $base . '/channel.php?slug=' . rawurlencode((string)$c['slug']);
    $thumb = rss_abs((string)($c['logo_url'] ?? ''), $base);
    $items[] = [
      'title' => 'Канал: ' . $c['title'],
      'link' => $link,
      'guid' => $link,
      'description' => mb_substr(strip_tags((string)($c['description'] ?? $c['title'])), 0, 400),
      'pub_date' => $c['created_at'] ?? null,
      'image' => $thumb,
    ];
  }
} catch (Throwable $e) {}

// Forum threads
try {
  $st = $pdo->query(
    "SELECT t.id, t.title, t.created_at, t.slug FROM forum_threads t
     ORDER BY t.id DESC LIMIT " . (int)$per
  );
  foreach ($st->fetchAll() ?: [] as $t) {
    $link = $base . '/forum_thread.php?id=' . (int)$t['id'];
    $items[] = [
      'title' => 'Форум: ' . $t['title'],
      'link' => $link,
      'guid' => $link,
      'description' => (string)$t['title'],
      'pub_date' => $t['created_at'] ?? null,
      'image' => '',
    ];
  }
} catch (Throwable $e) {}

// Resources
try {
  $st = $pdo->query("SELECT * FROM resources WHERE status = 'published' ORDER BY id DESC LIMIT " . (int)$per);
  foreach ($st->fetchAll() ?: [] as $r) {
    $link = $base . '/resource.php?slug=' . rawurlencode((string)$r['slug']);
    $thumb = rss_abs((string)($r['screenshot'] ?? ''), $base);
    $items[] = [
      'title' => 'Ресурс: ' . $r['title'],
      'link' => $link,
      'guid' => $link,
      'description' => mb_substr(strip_tags((string)($r['summary'] ?? $r['readme'] ?? $r['title'])), 0, 400),
      'pub_date' => $r['created_at'] ?? null,
      'image' => $thumb,
    ];
  }
} catch (Throwable $e) {}

// Sort by date desc
usort($items, static function ($a, $b) {
  $ta = strtotime((string)($a['pub_date'] ?? '')) ?: 0;
  $tb = strtotime((string)($b['pub_date'] ?? '')) ?: 0;
  return $tb <=> $ta;
});
$items = array_slice($items, 0, $limit);

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title><?= htmlspecialchars($siteName, ENT_XML1) ?> — лента</title>
  <link><?= htmlspecialchars($base, ENT_XML1) ?>/</link>
  <description>Видео, каналы, форум и ресурсы <?= htmlspecialchars($siteName, ENT_XML1) ?></description>
  <language>ru</language>
  <lastBuildDate><?= date('r') ?></lastBuildDate>
  <atom:link href="<?= htmlspecialchars($base . '/rss.php', ENT_XML1) ?>" rel="self" type="application/rss+xml"/>
<?php foreach ($items as $item):
  $img = $item['image'] ?? '';
?>
  <item>
    <title><?= htmlspecialchars((string)$item['title'], ENT_XML1) ?></title>
    <link><?= htmlspecialchars((string)$item['link'], ENT_XML1) ?></link>
    <guid isPermaLink="true"><?= htmlspecialchars((string)$item['guid'], ENT_XML1) ?></guid>
    <pubDate><?= rss_date($item['pub_date'] ?? null) ?></pubDate>
    <description><![CDATA[<?= $item['description'] ?>]]></description>
<?php if ($img !== ''): ?>
    <enclosure url="<?= htmlspecialchars($img, ENT_XML1) ?>" type="image/jpeg" length="0"/>
    <media:thumbnail url="<?= htmlspecialchars($img, ENT_XML1) ?>"/>
    <media:content url="<?= htmlspecialchars($img, ENT_XML1) ?>" medium="image"/>
<?php endif; ?>
  </item>
<?php endforeach; ?>
</channel>
</rss>
