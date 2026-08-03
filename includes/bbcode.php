<?php
/**
 * BBCode в духе XenForo — расширенный набор + вложенность + авто-ссылки + лёгкий markdown.
 * HTML всегда экранируется до разбора тегов.
 */

function bbcode_ensure_custom_tags_table(): void {
  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS bbcode_custom_tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tag_name VARCHAR(32) NOT NULL UNIQUE,
        replacement TEXT NOT NULL,
        example VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
  } catch (\Throwable $e) {}
}

function bbcode_custom_tags(): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  bbcode_ensure_custom_tags_table();
  try {
    $rows = db()->query("SELECT tag_name, replacement FROM bbcode_custom_tags WHERE is_active = 1")->fetchAll();
    $cache = [];
    foreach ($rows as $row) { $cache[$row['tag_name']] = $row['replacement']; }
  } catch (\Throwable $e) {
    $cache = [];
  }
  return $cache;
}

function bbcode_safe_url(string $url): ?string {
  $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
  if (!preg_match('#^https?://#i', $url)) return null;
  if (preg_match('#^(javascript|data|vbscript):#i', $url)) return null;
  return $url;
}

function bbcode_render_youtube(array $m): string {
  $raw = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
  $id = $raw;
  if (preg_match('#[?&]v=([a-zA-Z0-9_-]{6,})#', $raw, $mm)) $id = $mm[1];
  elseif (preg_match('#youtu\.be/([a-zA-Z0-9_-]{6,})#', $raw, $mm)) $id = $mm[1];
  elseif (preg_match('#youtube\.com/embed/([a-zA-Z0-9_-]{6,})#', $raw, $mm)) $id = $mm[1];
  elseif (!preg_match('#^[a-zA-Z0-9_-]{6,}$#', $id)) {
    return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
  }
  return '<div class="bb-media" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;margin:10px 0">'
    . '<iframe src="https://www.youtube.com/embed/' . htmlspecialchars($id, ENT_QUOTES) . '" loading="lazy" allowfullscreen '
    . 'style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe></div>';
}

function bbcode_render_font(array $m): string {
  $font = preg_replace('/[^a-zA-Zа-яА-ЯёЁ0-9 \-]/u', '', $m[1]);
  return '<span style="font-family:' . htmlspecialchars($font, ENT_QUOTES) . ',sans-serif">' . $m[2] . '</span>';
}

function bbcode_render_email(array $m): string {
  $email = filter_var(trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')), FILTER_VALIDATE_EMAIL);
  if (!$email) return htmlspecialchars($m[0], ENT_QUOTES);
  return '<a href="mailto:' . htmlspecialchars($email, ENT_QUOTES) . '">' . htmlspecialchars($email, ENT_QUOTES) . '</a>';
}

function bbcode_render_list(array $m): string {
  $ordered = !empty($m[1]);
  $body = $m[2] ?? '';
  $items = preg_split('/\[\*\]/', $body);
  $items = array_filter(array_map('trim', $items), fn($v) => $v !== '');
  $li = '';
  foreach ($items as $item) { $li .= '<li>' . $item . '</li>'; }
  $tag = $ordered ? 'ol' : 'ul';
  return '<' . $tag . ' class="bb-list">' . $li . '</' . $tag . '>';
}

function bbcode_size_to_css(string $size): string {
  $size = trim($size);
  if (preg_match('/^([1-7])$/', $size, $m)) {
    // XenForo-ish steps
    $map = [1 => '9px', 2 => '10px', 3 => '12px', 4 => '15px', 5 => '18px', 6 => '22px', 7 => '26px'];
    return $map[(int)$m[1]];
  }
  if (preg_match('/^(\d{1,3})px$/i', $size, $m)) {
    $px = max(8, min(48, (int)$m[1]));
    return $px . 'px';
  }
  if (preg_match('/^(\d{2,3})%$/', $size, $m)) {
    $p = max(50, min(200, (int)$m[1]));
    return $p . '%';
  }
  if (preg_match('/^(\d{1,3})$/', $size, $m)) {
    // bare number as px (XF style)
    $px = max(8, min(48, (int)$m[1]));
    return $px . 'px';
  }
  return '14px';
}

$BBCODE_SIMPLE_TAGS = [
  'b'       => '<strong>$1</strong>',
  'i'       => '<em>$1</em>',
  'u'       => '<span style="text-decoration:underline">$1</span>',
  's'       => '<span style="text-decoration:line-through">$1</span>',
  'strike'  => '<span style="text-decoration:line-through">$1</span>',
  'center'  => '<div style="text-align:center">$1</div>',
  'left'    => '<div style="text-align:left">$1</div>',
  'right'   => '<div style="text-align:right">$1</div>',
  'justify' => '<div style="text-align:justify">$1</div>',
  'sup'     => '<sup>$1</sup>',
  'sub'     => '<sub>$1</sub>',
  'indent'  => '<div class="bb-indent" style="margin-left:24px">$1</div>',
  'icode'   => '<code class="bb-inline-code">$1</code>',
  'kbd'     => '<kbd>$1</kbd>',
  'mark'    => '<mark>$1</mark>',
  'h1'      => '<h2 class="bb-h1">$1</h2>',
  'h2'      => '<h2>$1</h2>',
  'h3'      => '<h3>$1</h3>',
  'h4'      => '<h4>$1</h4>',
  'plain'   => '<span class="bb-plain">$1</span>',
  'highlight' => '<span class="bb-highlight">$1</span>',
];

function bbcode_callback_tags(?int $postId = null): array {
  return [
    '/\[youtube\](.*?)\[\/youtube\]/is' => 'bbcode_render_youtube',
    '/\[media=youtube\](.*?)\[\/media\]/is' => 'bbcode_render_youtube',
    '/\[font=(.*?)\](.*?)\[\/font\]/is' => 'bbcode_render_font',
    '/\[email\](.*?)\[\/email\]/is' => 'bbcode_render_email',
    '/\[email=(.*?)\](.*?)\[\/email\]/is' => function ($m) {
      $email = filter_var(trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')), FILTER_VALIDATE_EMAIL);
      if (!$email) return $m[0];
      return '<a href="mailto:' . htmlspecialchars($email, ENT_QUOTES) . '">' . $m[2] . '</a>';
    },
    '/\[list(?:=(1|a|A|i|I))?\](.*?)\[\/list\]/is' => 'bbcode_render_list',

    // color: name or #hex
    '/\[color=([a-zA-Z]{3,20}|#[0-9a-fA-F]{3,8})\](.*?)\[\/color\]/is' => function ($m) {
      return '<span style="color:' . htmlspecialchars($m[1], ENT_QUOTES) . '">' . $m[2] . '</span>';
    },

    // size: 1-7, 12, 12px, 150%
    '/\[size=([^\]]+)\](.*?)\[\/size\]/is' => function ($m) {
      $css = bbcode_size_to_css($m[1]);
      return '<span style="font-size:' . htmlspecialchars($css, ENT_QUOTES) . '">' . $m[2] . '</span>';
    },

    '/\[url=(https?:\/\/[^\]\s]+)\](.*?)\[\/url\]/is' => function ($m) {
      $u = bbcode_safe_url($m[1]);
      if (!$u) return $m[2];
      return '<a href="' . htmlspecialchars($u, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer nofollow">' . $m[2] . '</a>';
    },
    '/\[url\](https?:\/\/[^\]\s]+)\[\/url\]/is' => function ($m) {
      $u = bbcode_safe_url($m[1]);
      if (!$u) return htmlspecialchars($m[1], ENT_QUOTES);
      $show = htmlspecialchars($u, ENT_QUOTES);
      return '<a href="' . $show . '" target="_blank" rel="noopener noreferrer nofollow">' . $show . '</a>';
    },

    '/\[img\](https?:\/\/[^\]\s]+)\[\/img\]/is' => function ($m) {
      $u = bbcode_safe_url($m[1]);
      if (!$u) return '';
      return '<img class="bb-img" src="' . htmlspecialchars($u, ENT_QUOTES) . '" alt="" loading="lazy" style="max-width:100%;height:auto">';
    },
    '/\[img=(\d+)x(\d+)\](https?:\/\/[^\]\s]+)\[\/img\]/is' => function ($m) {
      $u = bbcode_safe_url($m[3]);
      if (!$u) return '';
      $w = min(1200, max(1, (int)$m[1]));
      $h = min(1200, max(1, (int)$m[2]));
      return '<img class="bb-img" src="' . htmlspecialchars($u, ENT_QUOTES) . '" width="' . $w . '" height="' . $h . '" alt="" loading="lazy" style="max-width:100%;height:auto">';
    },

    // QUOTE XenForo-style
    '/\[quote(?:=\"([^\"]+)\"|=([^\]]+))?\](.*?)\[\/quote\]/is' => function ($m) {
      $who = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
      // strip , post: N
      $who = preg_replace('/,\s*post:\s*\d+/i', '', $who);
      $who = htmlspecialchars($who, ENT_QUOTES, 'UTF-8');
      $head = $who !== '' ? '<div class="bb-quote-head">' . $who . ' писал(а):</div>' : '';
      return '<blockquote class="bb-quote">' . $head . '<div class="bb-quote-body">' . $m[3] . '</div></blockquote>';
    },

    // SPOILER with title
    '/\[spoiler(?:=\"([^\"]+)\"|=([^\]]+))?\](.*?)\[\/spoiler\]/is' => function ($m) {
      $title = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
      if ($title === '') $title = 'Спойлер (нажмите, чтобы открыть)';
      return '<details class="bb-spoiler"><summary>' . htmlspecialchars($title, ENT_QUOTES) . '</summary><div class="bb-spoiler-body">' . $m[3] . '</div></details>';
    },
    '/\[ispoiler\](.*?)\[\/ispoiler\]/is' => function ($m) {
      return '<span class="bb-ispoiler" title="наведите">' . $m[1] . '</span>';
    },
    '/\[hide\](.*?)\[\/hide\]/is' => function ($m) {
      return '<details class="bb-hide"><summary>Скрытый текст</summary>' . $m[1] . '</details>';
    },

    '/\[heading=([1-3])\](.*?)\[\/heading\]/is' => function ($m) {
      $l = (int)$m[1];
      return '<h' . ($l + 1) . ' class="bb-heading">' . $m[2] . '</h' . ($l + 1) . '>';
    },

    '/\[user\](.*?)\[\/user\]/is' => function ($m) {
      $name = trim(strip_tags($m[1]));
      $name = preg_replace('/[^a-zA-Zа-яА-ЯёЁ0-9_\-\.]/u', '', $name);
      if ($name === '') return '';
      return '<a class="bb-user" href="/profile?username=' . rawurlencode($name) . '">@' . htmlspecialchars($name, ENT_QUOTES) . '</a>';
    },
    '/\[user=(\d+)\](.*?)\[\/user\]/is' => function ($m) {
      $name = trim(strip_tags($m[2]));
      return '<a class="bb-user" href="/profile.php?id=' . (int)$m[1] . '">@' . htmlspecialchars($name, ENT_QUOTES) . '</a>';
    },

    '/\[php\](.*?)\[\/php\]/is' => function ($m) {
      return '<pre class="bb-code bb-php"><code>' . $m[1] . '</code></pre>';
    },
    '/\[html\](.*?)\[\/html\]/is' => function ($m) {
      return '<pre class="bb-code bb-html"><code>' . $m[1] . '</code></pre>';
    },

    // MEDIA generic
    '/\[media=rutube\](.*?)\[\/media\]/is' => function ($m) {
      $raw = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
      $id = $raw;
      if (preg_match('#rutube\.ru/video/([a-zA-Z0-9]+)#', $raw, $mm)) $id = $mm[1];
      return '<div class="bb-media" style="position:relative;padding-bottom:56.25%;height:0;margin:10px 0">'
        . '<iframe src="https://rutube.ru/play/embed/' . htmlspecialchars($id, ENT_QUOTES) . '" loading="lazy" allowfullscreen '
        . 'style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe></div>';
    },

    '/\[iframe\](https?:\/\/[^\]\s]+)\[\/iframe\]/is' => function ($m) {
      $u = bbcode_safe_url($m[1]);
      if (!$u) return '';
      return '<div class="bb-iframe"><iframe src="' . htmlspecialchars($u, ENT_QUOTES) . '" loading="lazy" style="width:100%;min-height:240px;border:0;border-radius:8px"></iframe></div>';
    },
    '/\[embed\](https?:\/\/[^\]\s]+)\[\/embed\]/is' => function ($m) {
      $u = bbcode_safe_url($m[1]);
      if (!$u) return '';
      // youtube shortcut
      if (preg_match('#(youtube\.com|youtu\.be)#i', $u)) {
        return bbcode_render_youtube([1 => $u]);
      }
      return '<p class="bb-embed-link"><a href="' . htmlspecialchars($u, ENT_QUOTES) . '" target="_blank" rel="noopener nofollow">' . htmlspecialchars($u, ENT_QUOTES) . '</a></p>';
    },
  ];
}

function bbcode_to_html(string $text, ?int $postId = null): string {
  if ($text === '') return '';

  // Экранируем HTML
  $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  // [code] ... [/code] — не парсить внутри
  $html = preg_replace_callback('/\[code(?:=([a-z0-9]+))?\](.*?)\[\/code\]/is', function ($m) {
    $lang = $m[1] !== '' ? ' data-lang="' . htmlspecialchars($m[1], ENT_QUOTES) . '"' : '';
    return '<pre class="bb-code"' . $lang . '><code>' . $m[2] . '</code></pre>';
  }, $html);

  // [table]
  $html = preg_replace_callback('/\[table\](.*?)\[\/table\]/is', function ($m) {
    $body = $m[1];
    $rowsHtml = '';
    if (preg_match_all('/\[tr\](.*?)\[\/tr\]/is', $body, $rowMatches)) {
      foreach ($rowMatches[1] as $rowBody) {
        $cellsHtml = '';
        if (preg_match_all('/\[t(h|d)\](.*?)\[\/t\1\]/is', $rowBody, $cellMatches, PREG_SET_ORDER)) {
          foreach ($cellMatches as $cell) {
            $tag = $cell[1] === 'h' ? 'th' : 'td';
            $cellsHtml .= '<' . $tag . '>' . trim($cell[2]) . '</' . $tag . '>';
          }
        }
        $rowsHtml .= '<tr>' . $cellsHtml . '</tr>';
      }
    }
    return '<div class="bb-table-wrap"><table class="bb-table">' . $rowsHtml . '</table></div>';
  }, $html);

  // Лёгкий markdown → bb (до тегов), текст уже escaped
  $html = preg_replace('/\*\*(.+?)\*\*/s', '[b]$1[/b]', $html);
  $html = preg_replace('/__(.+?)__/s', '[b]$1[/b]', $html);
  $html = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '[i]$1[/i]', $html);
  $html = preg_replace('/~~(.+?)~~/s', '[s]$1[/s]', $html);
  $html = preg_replace('/`([^`\n]+)`/', '[icode]$1[/icode]', $html);

  global $BBCODE_SIMPLE_TAGS;

  // Несколько проходов — вложенные [b][i][color]...
  for ($pass = 0; $pass < 4; $pass++) {
    foreach ($BBCODE_SIMPLE_TAGS as $tag => $template) {
      $html = preg_replace('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/is', $template, $html);
    }
    foreach (bbcode_custom_tags() as $tagName => $template) {
      $html = preg_replace('/\[' . preg_quote($tagName, '/') . '\](.*?)\[\/' . preg_quote($tagName, '/') . '\]/is', $template, $html);
    }
    foreach (bbcode_callback_tags($postId) as $pattern => $callback) {
      $html = preg_replace_callback($pattern, $callback, $html);
    }
  }

  $html = preg_replace('/\[hr\]/i', '<hr class="bb-hr">', $html);

  // Авто-ссылки на голые URL (не внутри href= уже)
  $html = preg_replace_callback(
    '/(?<!["\'>=])(https?:\/\/[^\s<>\[\]"\']+)/i',
    function ($m) {
      $u = rtrim($m[1], '.,);');
      $safe = bbcode_safe_url($u);
      if (!$safe) return $m[1];
      return '<a href="' . htmlspecialchars($safe, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer nofollow">' . htmlspecialchars($safe, ENT_QUOTES) . '</a>';
    },
    $html
  );

  $html = nl2br($html);
  return $html;
}
