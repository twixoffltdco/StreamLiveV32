<?php
// Простой импортёр RSS/Atom без внешних библиотек.
// ВК не отдаёт нативный RSS — для него нужно указывать URL RSS-моста (например, rsshub/vk2rss),
// в поле feed_url можно вписать такой мост-URL, дальше всё работает как с обычным RSS.

function fetch_rss_source(int $sourceId): array {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT * FROM rss_sources WHERE id = ?');
  $stmt->execute([$sourceId]);
  $source = $stmt->fetch();
  if (!$source) return ['ok' => false, 'error' => 'Источник не найден'];

  $ch = curl_init($source['feed_url']);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StreamLiveRSS/1.0)',
  ]);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  if ($body === false || $body === '') {
    return ['ok' => false, 'error' => 'Не удалось загрузить ленту' . ($err ? ": {$err}" : '')];
  }

  libxml_use_internal_errors(true);
  $xml = simplexml_load_string($body);
  if (!$xml) {
    return ['ok' => false, 'error' => 'Не удалось разобрать XML ленты (не похоже на RSS/Atom)'];
  }

  $items = [];
  if (isset($xml->channel->item)) { // RSS 2.0
    foreach ($xml->channel->item as $item) {
      $items[] = [
        'title' => trim((string)$item->title),
        'link' => trim((string)$item->link),
        'description' => trim((string)$item->description),
        'guid' => trim((string)($item->guid ?: $item->link)),
        'published_at' => !empty($item->pubDate) ? date('Y-m-d H:i:s', strtotime((string)$item->pubDate)) : null,
      ];
    }
  } elseif (isset($xml->entry)) { // Atom
    foreach ($xml->entry as $entry) {
      $link = '';
      if (isset($entry->link)) {
        foreach ($entry->link as $l) {
          $attrs = $l->attributes();
          if (!isset($attrs['rel']) || (string)$attrs['rel'] === 'alternate') { $link = (string)$attrs['href']; break; }
        }
      }
      $items[] = [
        'title' => trim((string)$entry->title),
        'link' => trim($link),
        'description' => trim((string)($entry->summary ?: $entry->content)),
        'guid' => trim((string)($entry->id ?: $link)),
        'published_at' => !empty($entry->updated) ? date('Y-m-d H:i:s', strtotime((string)$entry->updated)) : null,
      ];
    }
  } else {
    return ['ok' => false, 'error' => 'В ленте не найдено ни <item> (RSS), ни <entry> (Atom)'];
  }

  $inserted = 0;
  foreach ($items as $it) {
    if ($it['title'] === '' || $it['guid'] === '') continue;
    $stmt = $pdo->prepare(
      'INSERT IGNORE INTO rss_items (source_id, title, link, description, guid, published_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$sourceId, mb_substr($it['title'], 0, 490), mb_substr($it['link'], 0, 690), $it['description'], mb_substr($it['guid'], 0, 690), $it['published_at']]);
    if ($stmt->rowCount() > 0) {
      $inserted++;
      // Если источник привязан к разделу форума — публикуем новую запись как тему.
      if (!empty($source['forum_category_id'])) {
        rss_post_to_forum((int)$source['forum_category_id'], $it);
      }
    }
  }

  $pdo->prepare('UPDATE rss_sources SET last_fetched_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $sourceId]);

  return ['ok' => true, 'found' => count($items), 'inserted' => $inserted];
}

// Публикует импортированную RSS-запись как новую тему форума (от лица первого админа —
// у RSS-новостей нет "автора"-человека, поэтому используем системного публикатора).
function rss_post_to_forum(int $categoryId, array $item): void {
  $pdo = db();
  $authorId = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
  if (!$authorId) return; // нет ни одного админа — пропускаем, чтобы не падать с ошибкой внешнего ключа

  $title = mb_substr($item['title'], 0, 195);
  $body = trim(strip_tags((string)$item['description']));
  if ($item['link']) $body .= "\n\n[url]" . $item['link'] . "[/url]";

  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO forum_threads (category_id, user_id, title) VALUES (?, ?, ?)');
    $stmt->execute([$categoryId, $authorId, $title]);
    $threadId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO forum_posts (thread_id, user_id, message) VALUES (?, ?, ?)')
      ->execute([$threadId, $authorId, $body ?: $title]);
    $pdo->commit();
  } catch (\Throwable $e) {
    $pdo->rollBack();
  }
}

// ---- Исходящие RSS-ленты (форум, ТВ, радио) — чтобы копировать ссылку и вставлять
// в другие сервисы (например, автопостинг в группу ВК умеет читать RSS). ----

function render_rss_xml(string $title, string $link, string $description, array $items): void {
  header('Content-Type: application/rss+xml; charset=utf-8');
  $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  echo '<rss version="2.0"><channel>';
  echo '<title>' . $esc($title) . '</title>';
  echo '<link>' . $esc($link) . '</link>';
  echo '<description>' . $esc($description) . '</description>';
  echo '<language>ru</language>';
  foreach ($items as $it) {
    echo '<item>';
    echo '<title>' . $esc($it['title']) . '</title>';
    echo '<link>' . $esc($it['link']) . '</link>';
    echo '<guid isPermaLink="true">' . $esc($it['link']) . '</guid>';
    if (!empty($it['description'])) echo '<description>' . $esc($it['description']) . '</description>';
    if (!empty($it['pub_date'])) echo '<pubDate>' . date(DATE_RSS, strtotime($it['pub_date'])) . '</pubDate>';
    echo '</item>';
  }
  echo '</channel></rss>';
}
