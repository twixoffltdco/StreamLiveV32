<?php
/**
 * Поделиться: короткая ссылка, Telegram, VK, Max (копирует текст).
 */
if (!function_exists('shortlink_get') && is_file(__DIR__ . '/shortlink.php')) {
  require_once __DIR__ . '/shortlink.php';
}

function share_normalize_url(string $url): string {
  $url = trim($url);
  if ($url === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
  }
  if (strpos($url, 'http') !== 0) {
    $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
    if ($base === '') {
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $url = $base . '/' . ltrim($url, '/');
  }
  return $url;
}

function share_detect_kind(string $url): string {
  if (strpos($url, 'video') !== false) return 'v';
  if (strpos($url, 'forum') !== false) return 'f';
  if (strpos($url, 'channel') !== false) return 'c';
  if (strpos($url, 'profile') !== false) return 'p';
  if (strpos($url, 'resource') !== false) return 'r';
  return 'x';
}

function share_buttons(string $url = '', string $title = '', array $opts = []): string {
  $full = share_normalize_url($url);
  $title = $title !== '' ? $title : (defined('SITE_NAME') ? (string)SITE_NAME : 'StreamLive');
  $kind = $opts['kind'] ?? share_detect_kind($full);
  $short = $full;
  if (function_exists('shortlink_get')) {
    try {
      $short = shortlink_get($full, $kind, isset($opts['ref_id']) ? (int)$opts['ref_id'] : null);
    } catch (Throwable $e) {
      $short = $full;
    }
  }

  $uFull = rawurlencode($full);
  $uShort = rawurlencode($short);
  $t = rawurlencode($title);
  $id = 'share-' . substr(md5($short), 0, 10);

  $tg = 'https://t.me/share/url?url=' . $uShort . '&text=' . $t;
  $vk = 'https://vk.com/share.php?url=' . $uShort . '&title=' . $t;
  // Max не умеет /share?url= как чат — копируем текст для вставки в Max
  $maxText = $title . "\n" . $short;

  $b = 'display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid rgba(127,127,127,.35);background:rgba(127,127,127,.12);color:inherit;cursor:pointer;line-height:1.2';

  return '<div class="sl-share" id="' . htmlspecialchars($id) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0;padding:12px;border-radius:12px;background:rgba(127,127,127,.08);border:1px solid rgba(127,127,127,.2)">'
    . '<span style="font-size:13px;opacity:.85">↗ Поделиться</span>'
    . '<code style="font-size:12px;padding:6px 8px;border-radius:8px;background:rgba(0,0,0,.15);user-select:all">' . htmlspecialchars($short) . '</code>'
    . '<button type="button" class="sl-share-copy" data-url="' . htmlspecialchars($short, ENT_QUOTES) . '" style="' . $b . '">📋 Ссылка</button>'
    . '<a target="_blank" rel="noopener noreferrer" href="' . htmlspecialchars($tg, ENT_QUOTES) . '" style="' . $b . '">Telegram</a>'
    . '<a target="_blank" rel="noopener noreferrer" href="' . htmlspecialchars($vk, ENT_QUOTES) . '" style="' . $b . '">VK</a>'
    . '<button type="button" class="sl-share-max" data-text="' . htmlspecialchars($maxText, ENT_QUOTES) . '" style="' . $b . '" title="Скопировать для Max">Max</button>'
    . '</div>'
    . '<script>(function(){var r=document.getElementById(' . json_encode($id) . ');if(!r)return;'
    . 'function copy(t,btn,label){function ok(){var o=btn.textContent;btn.textContent="✓ Скопировано";setTimeout(function(){btn.textContent=label},1500)}'
    . 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(ok).catch(function(){prompt("Скопируйте:",t)})}else{prompt("Скопируйте:",t);ok()}}'
    . 'var b=r.querySelector(".sl-share-copy");if(b&&!b._b){b._b=1;b.addEventListener("click",function(){copy(b.getAttribute("data-url")||"",b,"📋 Ссылка")})}'
    . 'var m=r.querySelector(".sl-share-max");if(m&&!m._b){m._b=1;m.addEventListener("click",function(){copy(m.getAttribute("data-text")||"",m,"Max")})}'
    . '})();</script>';
}
