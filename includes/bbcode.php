<?php
// BBCode-рендер в духе XenForo. Всегда сперва экранирует HTML, теги BBCode применяются
// уже поверх экранированного текста — вставить сырой <script> через сообщение невозможно.
//
// ===== КАК ДОБАВИТЬ СВОЙ ТЕГ =====
// Простой парный тег вида [tag]текст[/tag] -> одна строка в $BBCODE_SIMPLE_TAGS ниже:
//   'mytag' => '<div class="my-style">$1</div>',
// $1 — это содержимое между открывающим и закрывающим тегом.
//
// Тег с параметром вида [tag=значение]текст[/tag] или что-то сложнее (само-закрывающийся,
// с проверкой значений и т.д.) — добавь функцию в $BBCODE_CALLBACK_TAGS ниже, по образцу
// уже существующих (bbcode_render_youtube, bbcode_render_font и т.д.)
//
// Третий способ — совсем без кода: /admin/bbcode_tags.php добавляет простой парный тег
// [tag]текст[/tag] через веб-форму, хранится в таблице bbcode_custom_tags. Подходит для
// несложных тегов-обёрток (свои цвета, значки, блоки) — то, что в XenForo называется
// "Custom BB Codes" в Admin CP.
// ===================================

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
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
  } catch (\Throwable $e) { /* нет прав CREATE — залей sql/migrations/025_bbcode_custom_tags.sql руками */ }
}

// Кеш на один HTTP-запрос — bbcode_to_html() может вызываться десятки раз на одной странице
// (список постов форума, комментарии и т.д.), незачем бить в БД на каждый вызов.
function bbcode_custom_tags(): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  bbcode_ensure_custom_tags_table();
  try {
    $rows = db()->query("SELECT tag_name, replacement FROM bbcode_custom_tags WHERE is_active = 1")->fetchAll();
    $cache = [];
    foreach ($rows as $row) { $cache[$row['tag_name']] = $row['replacement']; }
  } catch (\Throwable $e) {
    $cache = []; // таблицы ещё нет — просто нет пользовательских тегов в этот раз
  }
  return $cache;
}

function bbcode_render_youtube(array $m): string {
  // [youtube]ID_или_ссылка[/youtube]
  $input = trim($m[1]);
  $id = $input;
  if (preg_match('#(?:v=|youtu\.be/|embed/)([\w-]{6,})#', $input, $vm)) $id = $vm[1];
  if (!preg_match('/^[\w-]{6,20}$/', $id)) return '[youtube]' . htmlspecialchars($input, ENT_QUOTES) . '[/youtube]'; // не похоже на ID/ссылку — не рендерим как попало
  return '<div class="bb-video-wrap" style="position:relative;padding-top:56.25%;max-width:560px"><iframe src="https://www.youtube.com/embed/' . htmlspecialchars($id, ENT_QUOTES) . '" loading="lazy" allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe></div>';
}

function bbcode_render_font(array $m): string {
  // [font=Название]текст[/font] — только буквы/цифры/пробел/дефис в названии шрифта, без инъекции в CSS
  $font = preg_replace('/[^a-zA-Zа-яА-Я0-9 \-]/u', '', $m[1]);
  return '<span style="font-family:' . htmlspecialchars($font, ENT_QUOTES) . '">' . $m[2] . '</span>';
}

function bbcode_render_email(array $m): string {
  $email = filter_var(trim($m[1]), FILTER_VALIDATE_EMAIL);
  if (!$email) return htmlspecialchars($m[0], ENT_QUOTES);
  return '<a href="mailto:' . htmlspecialchars($email, ENT_QUOTES) . '">' . htmlspecialchars($email, ENT_QUOTES) . '</a>';
}

function bbcode_render_list(array $m): string {
  $ordered = isset($m[1]) && $m[1] !== '';
  $items = preg_split('/\[\*\]/', $m[2] ?? $m[1]);
  $items = array_filter(array_map('trim', $items), fn($v) => $v !== '');
  $li = '';
  foreach ($items as $item) { $li .= '<li>' . $item . '</li>'; }
  $tag = $ordered ? 'ol' : 'ul';
  return "<{$tag} class=\"bb-list\">{$li}</{$tag}>";
}

// Простые парные теги: [tag]содержимое[/tag] -> $1 подставляется в шаблон
$BBCODE_SIMPLE_TAGS = [
  'b'      => '<strong>$1</strong>',
  'i'      => '<em>$1</em>',
  'u'      => '<span style="text-decoration:underline">$1</span>',
  's'      => '<span style="text-decoration:line-through">$1</span>',
  'center' => '<div style="text-align:center">$1</div>',
  'left'   => '<div style="text-align:left">$1</div>',
  'right'  => '<div style="text-align:right">$1</div>',
  'justify'=> '<div style="text-align:justify">$1</div>',
  'sup'    => '<sup>$1</sup>',
  'sub'    => '<sub>$1</sub>',
  'indent' => '<div style="margin-left:24px">$1</div>',
  'spoiler'=> '<details class="bb-spoiler"><summary>Спойлер (нажмите, чтобы открыть)</summary>$1</details>',
  'hide'   => '<details class="bb-hide"><summary>Скрытый текст</summary>$1</details>',
  'icode'  => '<code class="bb-inline-code">$1</code>',
  'kbd'    => '<kbd>$1</kbd>',
  'mark'   => '<mark>$1</mark>',
  'h1'     => '<h1 class="bb-h1">$1</h1>',
  'h2'     => '<h2>$1</h2>',
  'h3'     => '<h3>$1</h3>',
  'h4'     => '<h4>$1</h4>',
  'plain'  => '<span class="bb-plain">$1</span>',
];

// Теги, которым нужна проверка/обработка параметров — регэксп => обработчик
function bbcode_callback_tags(?int $postId = null): array {
  return [
    '/\[youtube\](.*?)\[\/youtube\]/is'                 => 'bbcode_render_youtube',
    '/\[font=(.*?)\](.*?)\[\/font\]/is'                  => 'bbcode_render_font',
    '/\[email\](.*?)\[\/email\]/is'                      => 'bbcode_render_email',
    '/\[list(?:=(1))?\](.*?)\[\/list\]/is'                => 'bbcode_render_list',
    '/\[color=([a-zA-Z]+|#[0-9a-fA-F]{3,6})\](.*?)\[\/color\]/is' => function ($m) {
      return '<span style="color:' . htmlspecialchars($m[1], ENT_QUOTES) . '">' . $m[2] . '</span>';
    },
    '/\[size=([1-6])\](.*?)\[\/size\]/is' => function ($m) {
      $px = 11 + ((int)$m[1] * 3);
      return '<span style="font-size:' . $px . 'px">' . $m[2] . '</span>';
    },
    '/\[url=(https?:\/\/[^\]\s]+)\](.*?)\[\/url\]/is' => function ($m) {
      return '<a href="' . htmlspecialchars($m[1], ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer nofollow">' . $m[2] . '</a>';
    },
    '/\[url\](https?:\/\/[^\]\s]+)\[\/url\]/is' => function ($m) {
      return '<a href="' . htmlspecialchars($m[1], ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer nofollow">' . $m[1] . '</a>';
    },
    '/\[img\](https?:\/\/[^\]\s]+)\[\/img\]/is' => function ($m) {
      return '<img src="' . htmlspecialchars($m[1], ENT_QUOTES) . '" class="bb-img" loading="lazy" alt="">';
    },
    '/\[quote=(.*?)\](.*?)\[\/quote\]/is' => function ($m) {
      return '<div class="bb-quote"><div class="bb-quote-author">' . $m[1] . '</div>' . $m[2] . '</div>';
    },
    '/\[media=(youtube|vimeo|rutube|vk|dailymotion|coub)\](https?:\/\/[^\]\s]+)\[\/media\]/is' => function ($m) {
      require_once __DIR__ . '/video_embed.php';
      $embed = normalize_video_embed($m[1], html_entity_decode($m[2], ENT_QUOTES));
      return '<div class="bb-video-wrap" style="position:relative;padding-top:56.25%;max-width:720px"><iframe src="' . htmlspecialchars($embed, ENT_QUOTES) . '" loading="lazy" allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe></div>';
    },
    '/\[attach\](https?:\/\/[^\]\s]+)\[\/attach\]/is' => function ($m) use ($postId) {
      $url = $m[1];
      $filename = basename(parse_url($url, PHP_URL_PATH) ?: '') ?: 'файл';

      if ($postId === null) {
        // Рендер вне контекста конкретного поста (например, предпросмотр перед отправкой) —
        // считать клики некуда привязать, честно показываем обычную ссылку без счётчика.
        return '<a class="btn btn-outline btn-sm" href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener nofollow">📎 ' . htmlspecialchars($filename, ENT_QUOTES) . '</a>';
      }

      require_once __DIR__ . '/forum_attachments.php';
      $attachId = forum_attachment_get_or_create($postId, $url, $filename);
      $viewsCount = forum_attachment_views_count($attachId);

      return '<a class="bb-attachment" href="/forum_attachment_click.php?id=' . $attachId . '" target="_blank" rel="noopener nofollow" '
        . 'style="display:inline-flex;align-items:center;gap:8px;border:1px solid var(--border);border-radius:10px;padding:8px 12px;text-decoration:none;color:inherit;background:var(--card)">'
        . '<span style="font-size:20px">📎</span><span><b style="display:block;font-size:13px">' . htmlspecialchars($filename, ENT_QUOTES) . '</b>'
        . '<span style="font-size:11px;color:var(--text-dim)">' . $viewsCount . ' ' . forum_attachment_views_word($viewsCount) . '</span></span></a>';
    },

    '/\[spoiler=([^\]]+)\](.*?)\[\/spoiler\]/is' => function ($m) {
      $title = htmlspecialchars($m[1], ENT_QUOTES);
      return '<details class="bb-spoiler"><summary>' . $title . '</summary>' . $m[2] . '</details>';
    },
    '/\[hide=([^\]]+)\](.*?)\[\/hide\]/is' => function ($m) {
      $title = htmlspecialchars($m[1], ENT_QUOTES);
      return '<details class="bb-hide"><summary>' . $title . '</summary>' . $m[2] . '</details>';
    },
    '/\[user\]([a-zA-Z0-9_]{2,32})\[\/user\]/is' => function ($m) {
      $u = htmlspecialchars($m[1], ENT_QUOTES);
      return '<a class="bb-user" href="/u/' . $u . '">@' . $u . '</a>';
    },
    '/\[user=([a-zA-Z0-9_]{2,32})\](.*?)\[\/user\]/is' => function ($m) {
      $u = htmlspecialchars($m[1], ENT_QUOTES);
      return '<a class="bb-user" href="/u/' . $u . '">' . $m[2] . '</a>';
    },
    '/\[php\](.*?)\[\/php\]/is' => function ($m) {
      $id = 'bbcode-' . bin2hex(random_bytes(4));
      return '<div class="bb-code-block"><button type="button" class="bb-code-copy" data-target="' . $id . '">Копировать</button><pre class="bb-code bb-php" id="' . $id . '">' . $m[1] . '</pre></div>';
    },
    '/\[html\](.*?)\[\/html\]/is' => function ($m) {
      $id = 'bbcode-' . bin2hex(random_bytes(4));
      return '<div class="bb-code-block"><button type="button" class="bb-code-copy" data-target="' . $id . '">Копировать</button><pre class="bb-code bb-html" id="' . $id . '">' . $m[1] . '</pre></div>';
    },
    '/\[align=(left|center|right|justify)\](.*?)\[\/align\]/is' => function ($m) {
      return '<div style="text-align:' . $m[1] . '">' . $m[2] . '</div>';
    },
  ];
}

function bbcode_to_html(string $text, ?int $postId = null): string {
  $html = e($text);

  // [code]...[/code] — сохраняем как есть (без вложенных тегов внутри), с кнопкой "Копировать".
  $html = preg_replace_callback('/\[code\](.*?)\[\/code\]/is', function ($m) {
    $id = 'bbcode-' . bin2hex(random_bytes(4));
    return '<div class="bb-code-block"><button type="button" class="bb-code-copy" data-target="' . $id . '">Копировать</button><pre class="bb-code" id="' . $id . '">' . $m[1] . '</pre></div>';
  }, $html);

  // [table]...[/table] с [tr]/[td]/[th]
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

  // Простые парные теги — из реестра, добавлять новые сюда не нужно ничего трогать в коде
  global $BBCODE_SIMPLE_TAGS;
  foreach ($BBCODE_SIMPLE_TAGS as $tag => $template) {
    $html = preg_replace('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/is', $template, $html);
  }

  // Пользовательские теги из /admin/bbcode_tags.php — как в XenForo, где новый BB-код
  // добавляется через Admin CP без единой строчки кода. Работают по тому же принципу, что
  // и BBCODE_SIMPLE_TAGS выше (шаблон с $1 на месте содержимого тега), просто источник — БД,
  // а не массив в коде.
  foreach (bbcode_custom_tags() as $tagName => $template) {
    $html = preg_replace('/\[' . preg_quote($tagName, '/') . '\](.*?)\[\/' . preg_quote($tagName, '/') . '\]/is', $template, $html);
  }

  // Теги с параметрами/проверками — тоже из реестра
  foreach (bbcode_callback_tags($postId) as $pattern => $callback) {
    $html = preg_replace_callback($pattern, $callback, $html);
  }

  // Простая markdown-совместимость для README ресурсов: # / ## заголовки и ```code```.
  $html = preg_replace('/^### (.+)$/m', '[h3]$1[/h3]', $html);
  $html = preg_replace('/^## (.+)$/m', '[h2]$1[/h2]', $html);
  $html = preg_replace('/^# (.+)$/m', '[h2]$1[/h2]', $html);

  foreach ($BBCODE_SIMPLE_TAGS as $tag => $template) {
    $html = preg_replace('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/is', $template, $html);
  }

  // [hr] — самозакрывающийся, без содержимого
  $html = preg_replace('/\[hr\]/i', '<hr class="bb-hr">', $html);

  // Переносы строк вне блочных тегов
  $html = nl2br($html);

  return $html;
}
