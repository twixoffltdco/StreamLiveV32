<?php
/**
 * Подключай после успешного создания контента:
 *   require_once __DIR__ . '/social_bots.php';
 *   bots_notify_forum_thread($id, $title, $url);
 *   bots_notify_video($id, $title, $url, $thumb);
 */
require_once __DIR__ . '/social_bots.php';

function bots_hook_rss_item(string $title, string $link, ?string $summary = null): void {
  $s = bots_settings();
  if (empty($s['auto_rss'])) return;
  if (empty($s['tg_enabled']) && empty($s['vk_enabled'])) return;
  bots_enqueue('both', $summary ?: $title, [
    'title' => '📰 ' . $title,
    'link' => $link,
    'source' => 'rss',
  ]);
}
