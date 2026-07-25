<?php
// Обычная ссылка на YouTube/VK/Rutube (не embed-формат) блокируется браузером в iframe
// из-за X-Frame-Options — пользователь видит "отказано в подключении" вместо плеера.
// Приводим известные форматы к embed-варианту автоматически при сохранении источника.
// Поддержка ?file= для доверенных пользователей: некоторые ссылки на медиа приходят
// обёрнутыми в чужую плеер-страницу вида .../embed?file=https://cdn.example.com/video.m3u8
// (частый формат у JW Player и похожих встраиваемых плееров). Пытаться встроить саму
// страницу-обёртку как <video src="..."> не сработает — это HTML-страница, а не поток.
// Достаём реальную ссылку на поток из параметра file, если он есть.
function extract_file_param_url(string $url): ?string {
  $parts = parse_url($url);
  if (empty($parts['query'])) return null;
  parse_str($parts['query'], $query);
  if (empty($query['file'])) return null;
  $inner = trim((string)$query['file']);
  // Параметр может быть ещё раз urlencode'нут — пробуем раскодировать, если похоже на это.
  if (strpos($inner, '%3A%2F%2F') !== false || strpos($inner, '%2F') !== false) {
    $decoded = urldecode($inner);
    if (filter_var($decoded, FILTER_VALIDATE_URL)) $inner = $decoded;
  }
  $looksLikeMedia = preg_match('/\.(m3u8|mp4|mpd)(\?.*)?$/i', $inner)
    || stripos($inner, 'https://') === 0 || stripos($inner, 'file://') === 0;
  return $looksLikeMedia ? $inner : null;
}

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
