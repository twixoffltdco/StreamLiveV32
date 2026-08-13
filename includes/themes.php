<?php
function themes_dir(): string { return __DIR__ . '/../storage/themes'; }
function themes_index_file(): string { return themes_dir() . '/themes.json'; }
function themes_public_css_dir(): string { return __DIR__ . '/../assets/themes'; }

function themes_bootstrap(bool $strict = false): void {
  foreach ([themes_dir(), themes_public_css_dir()] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      if ($strict) throw new RuntimeException('Нет доступа к папке ' . $dir);
      return;
    }
    if ($strict && !is_writable($dir)) throw new RuntimeException('Папка недоступна для записи: ' . $dir);
  }
  // Индекс: если пустой/нет — собираем из CSS в assets/themes (работает на любом хосте)
  $needSeed = !is_file(themes_index_file());
  if (!$needSeed) {
    $raw = @file_get_contents(themes_index_file());
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || count($data) === 0) $needSeed = true;
  }
  if ($needSeed) {
    themes_seed_from_css_dir();
  }
}

/** Сканирует assets/themes/*.css и пишет storage/themes/themes.json */
function themes_seed_from_css_dir(): void {
  $dir = themes_public_css_dir();
  if (!is_dir($dir)) return;
  $skip = ['header.php', 'footer.php', 'headershorts.php'];
  $names = [
    'flexdev' => 'FlexDev',
    'emerald' => 'Emerald',
    'midnight' => 'Midnight',
    'neon' => 'Neon',
    'paper' => 'Paper',
    'prohub' => 'ProHub Nexus',
    'gitroid' => 'Gitroid',
  ];
  $themes = [];
  foreach (glob($dir . '/*.css') ?: [] as $cssPath) {
    $base = basename($cssPath);
    if (in_array($base, $skip, true)) continue;
    $slug = preg_replace('/\.css$/i', '', $base);
    if ($slug === '' || $slug === 'style') continue;
    $name = $names[$slug] ?? ucfirst(str_replace(['-', '_'], ' ', $slug));
    $themes[] = [
      'slug' => $slug,
      'name' => $name,
      'css' => '/assets/themes/' . $slug . '.css',
      'header' => null,
      'footer' => null,
    ];
  }
  // Сортируем: FlexDev первым
  usort($themes, static function ($a, $b) {
    if (($a['slug'] ?? '') === 'flexdev') return -1;
    if (($b['slug'] ?? '') === 'flexdev') return 1;
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });
  @mkdir(themes_dir(), 0775, true);
  @file_put_contents(
    themes_index_file(),
    json_encode(array_values($themes), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
  );
}

function themes_slug(string $name): string {
  $slug = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
  $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
  $slug = strtr($slug, $map);
  return trim(preg_replace('/[^a-z0-9_-]+/i', '-', $slug), '-') ?: 'theme';
}

function themes_all(): array {
  themes_bootstrap();
  if (!is_file(themes_index_file())) return [];
  $data = json_decode((string)file_get_contents(themes_index_file()), true);
  if (!is_array($data) || !$data) {
    themes_seed_from_css_dir();
    $data = json_decode((string)@file_get_contents(themes_index_file()), true);
  }
  return is_array($data) ? $data : [];
}

function themes_save_all(array $themes): void {
  themes_bootstrap(true);
  if (file_put_contents(themes_index_file(), json_encode(array_values($themes), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    throw new RuntimeException('Не удалось записать индекс тем.');
  }
}

function themes_write_file(string $path, string $content): void {
  if (file_put_contents($path, $content, LOCK_EX) === false) {
    throw new RuntimeException('Не удалось записать файл ' . basename($path));
  }
}

function themes_save(string $slug, string $name, string $css, string $header, string $footer): void {
  themes_bootstrap(true);
  themes_write_file(themes_public_css_dir() . "/$slug.css", $css);
  themes_write_file(themes_dir() . "/$slug.header.php", $header);
  themes_write_file(themes_dir() . "/$slug.footer.php", $footer);
  $themes = themes_all();
  $themes = array_values(array_filter($themes, fn($t) => ($t['slug'] ?? '') !== $slug));
  $themes[] = [
    'slug' => $slug,
    'name' => $name,
    'css' => '/assets/themes/' . $slug . '.css',
    'header' => "$slug.header.php",
    'footer' => "$slug.footer.php",
  ];
  themes_save_all($themes);
}

function themes_active(): ?array {
  $slug = $_COOKIE['site_theme'] ?? '';
  if ($slug === '') return null;
  foreach (themes_all() as $t) {
    if (($t['slug'] ?? '') === $slug) return $t;
  }
  return null;
}

function theme_safe_include(string $path): void {
  if ($path && is_file($path)) {
    try { include $path; } catch (Throwable $e) {}
  }
}
