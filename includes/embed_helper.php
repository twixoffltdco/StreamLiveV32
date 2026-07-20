<?php
// Обычная ссылка на YouTube/VK/Rutube (не embed-формат) блокируется браузером в iframe
// из-за X-Frame-Options — пользователь видит "отказано в подключении" вместо плеера.
// Приводим известные форматы к embed-варианту автоматически при сохранении источника.
function normalize_embed_url(string $type, string $url): string {
  try {
    if ($type === 'youtube') {
      if (strpos($url, 'youtube.com/embed/') !== false) return $url;
      $id = null;
      if (preg_match('/[?&]v=([\w-]{6,})/', $url, $m)) $id = $m[1];
      if (!$id && preg_match('#youtu\.be/([\w-]{6,})#', $url, $m)) $id = $m[1];
      if (!$id && preg_match('#youtube\.com/shorts/([\w-]{6,})#', $url, $m)) $id = $m[1];
      if ($id) return "https://www.youtube.com/embed/{$id}";
    } elseif ($type === 'vk') {
      if (strpos($url, 'video_ext.php') !== false) return $url;
      if (preg_match('/video(-?\d+)_(\d+)/', $url, $m)) {
        return "https://vk.com/video_ext.php?oid={$m[1]}&id={$m[2]}&hd=2";
      }
    } elseif ($type === 'rutube') {
      if (strpos($url, 'rutube.ru/play/embed/') !== false) return $url;
      if (preg_match('#rutube\.ru/video/([a-f0-9]+)#i', $url, $m)) {
        return "https://rutube.ru/play/embed/{$m[1]}/";
      }
    }
  } catch (Exception $e) { /* если не распознали — сохраняем ссылку как есть */ }
  return $url;
}

// Строгая проверка: раньше в канал можно было вписать ЛЮБУЮ ссылку (хоть нерабочую,
// хоть обычную страницу сайта) — теперь пропускаем только то, что реально похоже на
// embed-плеер видео. Возвращает [true, нормализованный_url] либо [false, текст_ошибки].
function validate_player_url(string $type, string $url): array {
  $url = trim($url);
  if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return [false, 'Некорректная ссылка'];
  if (stripos($url, 'https://') !== 0) return [false, 'Ссылка должна начинаться с https:// (небезопасный http:// не принимается)'];

  $normalized = normalize_embed_url($type, $url);

  switch ($type) {
    case 'youtube':
      if (strpos($normalized, 'youtube.com/embed/') === false) {
        return [false, 'Это не похоже на видео YouTube. Дайте ссылку вида youtube.com/watch?v=ID, youtu.be/ID или сразу youtube.com/embed/ID — остальное сделаем сами.'];
      }
      break;
    case 'vk':
      if (strpos($normalized, 'video_ext.php') === false) {
        return [false, 'Это не похоже на видео ВКонтакте. Дайте ссылку вида vk.com/video123_456.'];
      }
      break;
    case 'rutube':
      if (strpos($normalized, '/play/embed/') === false) {
        return [false, 'Это не похоже на видео Rutube. Дайте ссылку вида rutube.ru/video/HASH/.'];
      }
      break;
    case 'm3u8':
      if (stripos($normalized, '.m3u8') === false) return [false, 'Ссылка на m3u8-поток должна вести на файл .m3u8'];
      break;
    case 'mp4':
      if (!preg_match('/\.(mp4|webm|mov)(\?.*)?$/i', $normalized)) {
        return [false, 'Ссылка на видеофайл должна вести на .mp4, .webm или .mov'];
      }
      break;
    case 'iframe':
      $host = strtolower((string)parse_url($normalized, PHP_URL_HOST));
      $path = strtolower((string)parse_url($normalized, PHP_URL_PATH));
      $knownPlayerHosts = ['tatnet.app', 'twitch.tv', 'vimeo.com', 'ok.ru', 'kinobox.tv', 'kodik.info', 'kinobd.net', 'dailymotion.com'];
      $looksLikePlayer = strpos($path, 'embed') !== false || strpos($path, 'player') !== false || strpos($path, 'video_ext') !== false;
      $isKnownHost = false;
      foreach ($knownPlayerHosts as $h) {
        if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) { $isKnownHost = true; break; }
      }
      if (!$looksLikePlayer && !$isKnownHost) {
        return [false, 'Похоже, это обычная страница сайта, а не видеоплеер. Разрешены только прямые embed-ссылки на видеоплееры (адрес должен содержать "embed"/"player", либо быть с известной видеоплатформы).'];
      }
      break;
    default:
      return [false, 'Неизвестный тип источника'];
  }
  return [true, $normalized];
}
