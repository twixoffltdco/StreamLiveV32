<?php
function themes_dir(): string { return __DIR__ . '/../storage/themes'; }
function themes_index_file(): string { return themes_dir() . '/themes.json'; }
function themes_public_css_dir(): string { return __DIR__ . '/../assets/themes'; }

function themes_bootstrap(bool $strict = false): void {
  try {
    foreach ([themes_dir(), themes_public_css_dir()] as $dir) {
      if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
    if (!is_file(themes_index_file()) || in_array(trim((string)@file_get_contents(themes_index_file())), ['', '[]', 'null'], true)) {
      themes_seed_from_css_dir();
    }
  } catch (Throwable $e) {
    if ($strict) throw $e;
  }
}

function themes_seed_from_css_dir(): void {
  try {
    $dir = themes_public_css_dir();
    if (!is_dir($dir)) return;
    $names = ['flexdev'=>'FlexDev','emerald'=>'Emerald','midnight'=>'Midnight','neon'=>'Neon','paper'=>'Paper','prohub'=>'ProHub Nexus','gitroid'=>'Gitroid'];
    $themes = [];
    foreach (glob($dir . '/*.css') ?: [] as $cssPath) {
      $slug = preg_replace('/\.css$/i', '', basename($cssPath));
      if ($slug === '' || in_array($slug, ['style'], true)) continue;
      $themes[] = ['slug'=>$slug,'name'=>$names[$slug]??ucfirst($slug),'css'=>'/assets/themes/'.$slug.'.css','header'=>null,'footer'=>null];
    }
    @mkdir(themes_dir(), 0775, true);
    @file_put_contents(themes_index_file(), json_encode(array_values($themes), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
  } catch (Throwable $e) {}
}

function themes_all(): array {
  try {
    themes_bootstrap();
    if (!is_file(themes_index_file())) return [];
    $data = json_decode((string)@file_get_contents(themes_index_file()), true);
    if (!is_array($data) || !$data) { themes_seed_from_css_dir(); $data = json_decode((string)@file_get_contents(themes_index_file()), true); }
    return is_array($data) ? $data : [];
  } catch (Throwable $e) { return []; }
}

function themes_active(): ?array {
  try {
    $slug = $_COOKIE['site_theme'] ?? '';
    if ($slug === '') return null;
    foreach (themes_all() as $t) if (($t['slug'] ?? '') === $slug) return $t;
  } catch (Throwable $e) {}
  return null;
}

function theme_safe_include(string $path): void {
  if ($path && is_file($path)) { try { include $path; } catch (Throwable $e) {} }
}

function themes_save_all(array $themes): void {
  themes_bootstrap(true);
  file_put_contents(themes_index_file(), json_encode(array_values($themes), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function themes_write_file(string $path, string $content): void {
  file_put_contents($path, $content, LOCK_EX);
}
function themes_save(string $slug, string $name, string $css, string $header, string $footer): void {
  themes_bootstrap(true);
  themes_write_file(themes_public_css_dir()."/$slug.css", $css);
  themes_write_file(themes_dir()."/$slug.header.php", $header);
  themes_write_file(themes_dir()."/$slug.footer.php", $footer);
  $themes = array_values(array_filter(themes_all(), fn($t)=>($t['slug']??'')!==$slug));
  $themes[] = ['slug'=>$slug,'name'=>$name,'css'=>'/assets/themes/'.$slug.'.css','header'=>"$slug.header.php",'footer'=>"$slug.footer.php"];
  themes_save_all($themes);
}
function themes_slug(string $name): string {
  $slug = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
  return trim(preg_replace('/[^a-z0-9_-]+/i', '-', $slug), '-') ?: 'theme';
}
