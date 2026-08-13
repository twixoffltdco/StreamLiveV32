<?php
/**
 * Доп. BB-коды в стиле XenForo (подключается из bbcode.php или перед render).
 */
if (!function_exists('bbcode_merge_extra_simple_tags')) {
function bbcode_merge_extra_simple_tags(array &$tags): void {
  $extra = [
    'b' => $tags['b'] ?? '<strong>$1</strong>',
    'i' => $tags['i'] ?? '<em>$1</em>',
    'u' => $tags['u'] ?? '<span style="text-decoration:underline">$1</span>',
    's' => $tags['s'] ?? '<s>$1</s>',
    'center' => $tags['center'] ?? '<div style="text-align:center">$1</div>',
    'left' => $tags['left'] ?? '<div style="text-align:left">$1</div>',
    'right' => $tags['right'] ?? '<div style="text-align:right">$1</div>',
    'justify' => $tags['justify'] ?? '<div style="text-align:justify">$1</div>',
    'sup' => $tags['sup'] ?? '<sup>$1</sup>',
    'sub' => $tags['sub'] ?? '<sub>$1</sub>',
    'heading' => '<h3 class="bb-heading">$1</h3>',
    'important' => '<div class="bb-important" style="border-left:4px solid #f59e0b;padding:8px 12px;background:rgba(245,158,11,.1);margin:8px 0">$1</div>',
    'warning' => '<div class="bb-warning" style="border-left:4px solid #ef4444;padding:8px 12px;background:rgba(239,68,68,.1);margin:8px 0">$1</div>',
    'info' => '<div class="bb-info" style="border-left:4px solid #3b82f6;padding:8px 12px;background:rgba(59,130,246,.1);margin:8px 0">$1</div>',
    'success' => '<div class="bb-success" style="border-left:4px solid #22c55e;padding:8px 12px;background:rgba(34,197,94,.1);margin:8px 0">$1</div>',
    'table' => '<div class="bb-table-wrap" style="overflow:auto"><table class="bb-table">$1</table></div>',
    'tr' => '<tr>$1</tr>',
    'th' => '<th>$1</th>',
    'td' => '<td>$1</td>',
    'hr' => '<hr class="bb-hr">',
    'nobbc' => '<span class="bb-nobbc">$1</span>',
  ];
  foreach ($extra as $k => $v) {
    if (!isset($tags[$k])) $tags[$k] = $v;
  }
}
}

if (!function_exists('bbcode_extra_callback_patterns')) {
function bbcode_extra_callback_patterns(): array {
  return [
    '/\[color=(#[0-9a-fA-F]{3,8}|[a-zA-Z]+)\](.*?)\[\/color\]/is' => function ($m) {
      $c = preg_replace('/[^#a-zA-Z0-9]/', '', $m[1]);
      return '<span style="color:' . htmlspecialchars($c, ENT_QUOTES) . '">' . $m[2] . '</span>';
    },
    '/\[size=(\d{1,3})\](.*?)\[\/size\]/is' => function ($m) {
      $n = max(8, min(36, (int)$m[1]));
      return '<span style="font-size:' . $n . 'px">' . $m[2] . '</span>';
    },
    '/\[font=([^\]]+)\](.*?)\[\/font\]/is' => function ($m) {
      $f = preg_replace('/[^a-zA-Zа-яА-ЯёЁ0-9 \-_]/u', '', $m[1]);
      return '<span style="font-family:' . htmlspecialchars($f, ENT_QUOTES) . ',sans-serif">' . $m[2] . '</span>';
    },
    '/\[url=(https?:\/\/[^\]]+)\](.*?)\[\/url\]/is' => function ($m) {
      $u = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
      return '<a href="' . $u . '" target="_blank" rel="noopener nofollow">' . $m[2] . '</a>';
    },
    '/\[url\](https?:\/\/[^\[]+)\[\/url\]/is' => function ($m) {
      $u = htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8');
      return '<a href="' . $u . '" target="_blank" rel="noopener nofollow">' . $u . '</a>';
    },
    '/\[img\](https?:\/\/[^\[]+)\[\/img\]/is' => function ($m) {
      $u = htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8');
      return '<img src="' . $u . '" alt="" loading="lazy" style="max-width:100%;height:auto;border-radius:8px">';
    },
    '/\[quote(?:=([^\]]+))?\](.*?)\[\/quote\]/is' => function ($m) {
      $who = $m[1] !== '' ? '<div class="bb-quote-author">' . htmlspecialchars($m[1], ENT_QUOTES) . '</div>' : '';
      return '<blockquote class="bb-quote">' . $who . $m[2] . '</blockquote>';
    },
    '/\[spoiler(?:=([^\]]+))?\](.*?)\[\/spoiler\]/is' => function ($m) {
      $lab = $m[1] !== '' ? htmlspecialchars($m[1], ENT_QUOTES) : 'Спойлер';
      return '<details class="bb-spoiler"><summary>' . $lab . '</summary><div>' . $m[2] . '</div></details>';
    },
    '/\[list\](.*?)\[\/list\]/is' => function ($m) {
      $body = preg_replace('/\[\*\]\s*/', '</li><li>', $m[1]);
      $body = '<li>' . ltrim($body, '</li>') . '</li>';
      return '<ul class="bb-list">' . $body . '</ul>';
    },
    '/\[list=1\](.*?)\[\/list\]/is' => function ($m) {
      $body = preg_replace('/\[\*\]\s*/', '</li><li>', $m[1]);
      $body = '<li>' . ltrim($body, '</li>') . '</li>';
      return '<ol class="bb-list">' . $body . '</ol>';
    },
    '/\[user=(\d+)\](.*?)\[\/user\]/is' => function ($m) {
      $id = (int)$m[1];
      $name = strip_tags($m[2]);
      return '<a class="bb-user" href="/profile.php?id=' . $id . '">@' . htmlspecialchars($name, ENT_QUOTES) . '</a>';
    },
    '/\[attach\](\d+)\[\/attach\]/is' => function ($m) {
      return '<span class="bb-attach">[вложение #' . (int)$m[1] . ']</span>';
    },
  ];
}
}
