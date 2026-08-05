<?php
/**
 * VK-совместимый вывод RSS 2.0
 */
function rss_vk_base(): string {
  if (defined('SITE_URL') && SITE_URL) return rtrim((string)SITE_URL, '/');
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function rss_vk_abs(string $url, ?string $base = null): string {
  $url = trim($url);
  if ($url === '') return '';
  if (preg_match('#^https?://#i', $url)) return $url;
  $base = $base ?? rss_vk_base();
  return rtrim($base, '/') . '/' . ltrim($url, '/');
}

function rss_vk_date($v): string {
  $ts = is_numeric($v) ? (int)$v : strtotime((string)$v);
  if (!$ts) $ts = time();
  return date('r', $ts);
}

/**
 * @param array $channel [title, link, description]
 * @param array $items [ [title, link, guid?, description, pub_date, image?], ... ]
 */
function rss_vk_render(array $channel, array $items): void {
  $base = rss_vk_base();
  header('Content-Type: application/rss+xml; charset=utf-8');
  header('Cache-Control: public, max-age=180');
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  $self = rss_vk_abs($_SERVER['REQUEST_URI'] ?? '/rss.php', $base);
  echo '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
  echo "<channel>\n";
  echo '<title>' . htmlspecialchars((string)$channel['title'], ENT_XML1) . "</title>\n";
  echo '<link>' . htmlspecialchars((string)$channel['link'], ENT_XML1) . "</link>\n";
  echo '<description>' . htmlspecialchars((string)($channel['description'] ?? ''), ENT_XML1) . "</description>\n";
  echo "<language>ru</language>\n";
  echo '<lastBuildDate>' . date('r') . "</lastBuildDate>\n";
  echo '<atom:link href="' . htmlspecialchars($self, ENT_XML1) . '" rel="self" type="application/rss+xml"/>' . "\n";
  foreach ($items as $it) {
    $link = (string)($it['link'] ?? '');
    $guid = (string)($it['guid'] ?? $link);
    $img = rss_vk_abs((string)($it['image'] ?? ''), $base);
    echo "  <item>\n";
    echo '    <title>' . htmlspecialchars((string)($it['title'] ?? ''), ENT_XML1) . "</title>\n";
    echo '    <link>' . htmlspecialchars($link, ENT_XML1) . "</link>\n";
    echo '    <guid isPermaLink="true">' . htmlspecialchars($guid, ENT_XML1) . "</guid>\n";
    echo '    <pubDate>' . rss_vk_date($it['pub_date'] ?? null) . "</pubDate>\n";
    echo '    <description><![CDATA[' . (string)($it['description'] ?? '') . "]]></description>\n";
    if ($img !== '') {
      echo '    <enclosure url="' . htmlspecialchars($img, ENT_XML1) . '" type="image/jpeg" length="0"/>' . "\n";
      echo '    <media:thumbnail url="' . htmlspecialchars($img, ENT_XML1) . '"/>' . "\n";
      echo '    <media:content url="' . htmlspecialchars($img, ENT_XML1) . '" medium="image"/>' . "\n";
    }
    echo "  </item>\n";
  }
  echo "</channel>\n</rss>";
  exit;
}
