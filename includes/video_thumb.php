<?php
/**
 * Обложка: сохранённая → oEmbed/OG-шаблоны платформ → пусто (Canvas на клиенте).
 */
function video_thumb_from_platform(array $v): string {
  $existing = trim((string)($v['thumbnail_url'] ?? ''));
  if ($existing !== '' && stripos($existing, 'placeholder') === false) {
    return $existing;
  }
  $url = (string)($v['source_url'] ?? $v['embed_url'] ?? '');
  $platform = strtolower((string)($v['platform'] ?? ''));

  // YouTube
  if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/shorts/)([a-zA-Z0-9_-]{6,})~', $url, $m)
      || ($platform === 'youtube' && preg_match('~([a-zA-Z0-9_-]{11})~', $url, $m))) {
    return 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg';
  }
  // VK video
  if (preg_match('~vk\.com/video(-?\d+)_(\d+)~', $url, $m) || preg_match('~vkvideo\.ru/video(-?\d+)_(\d+)~', $url, $m)) {
    // VK не даёт прямой CDN без API — оставим canvas/oembed
  }
  // Rutube
  if (preg_match('~rutube\.ru/(?:video|play/embed)/([a-f0-9]{32})~i', $url, $m)) {
    return 'https://rutube.ru/api/video/' . $m[1] . '/thumbnail/?redirect=1';
  }
  // Vimeo
  if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
    // без API нет стабильного URL — клиент/oembed
  }
  // Dailymotion
  if (preg_match('~dailymotion\.com/video/([a-zA-Z0-9]+)~', $url, $m)) {
    return 'https://www.dailymotion.com/thumbnail/video/' . $m[1];
  }
  // Twitch clip/vod — skip
  return '';
}

function video_thumb_url(array $v): string {
  $t = video_thumb_from_platform($v);
  return $t !== '' ? $t : (string)($v['thumbnail_url'] ?? '');
}

function video_canvas_src(array $v): string {
  $platform = strtolower((string)($v['platform'] ?? ''));
  $embed = trim((string)($v['embed_url'] ?? ''));
  $src = trim((string)($v['source_url'] ?? ''));
  foreach ([$embed, $src] as $u) {
    if ($u !== '' && preg_match('/\.(mp4|webm|ogg|mov|m3u8)($|\?)/i', $u)) {
      return $u;
    }
  }
  if (in_array($platform, ['mp4', 'm3u8', 'webm'], true)) {
    return $embed !== '' ? $embed : $src;
  }
  return '';
}
