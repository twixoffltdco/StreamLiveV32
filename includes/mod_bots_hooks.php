<?php
require_once __DIR__ . '/mod_bots.php';

/** Вызвать после публикации ресурса */
function mod_bots_on_resource_published(int $id, string $title, string $url): void {
  mod_bots_broadcast_new_content('resource', $title, $url);
}
function mod_bots_on_video_published(int $id, string $title, string $url): void {
  mod_bots_broadcast_new_content('video', $title, $url);
}
function mod_bots_on_forum_thread(int $id, string $title, string $url): void {
  mod_bots_broadcast_new_content('forum', $title, $url);
  if (function_exists('bots_notify_forum_thread')) {
    bots_notify_forum_thread($id, $title, $url);
  }
}
