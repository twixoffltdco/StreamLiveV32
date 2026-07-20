<?php
function themes_dir(): string { return __DIR__ . '/../storage/themes'; }
function themes_index_file(): string { return themes_dir() . '/themes.json'; }
function themes_public_css_dir(): string { return __DIR__ . '/../assets/themes'; }
function themes_bootstrap(): void {
  if (!is_dir(themes_dir())) @mkdir(themes_dir(), 0775, true);
  if (!is_dir(themes_public_css_dir())) @mkdir(themes_public_css_dir(), 0775, true);
  if (!file_exists(themes_index_file())) file_put_contents(themes_index_file(), json_encode([], JSON_UNESCAPED_UNICODE));
}
function themes_slug(string $name): string { return trim(preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($name)), '-') ?: 'theme'; }
function themes_all(): array { themes_bootstrap(); $data=json_decode((string)file_get_contents(themes_index_file()), true); return is_array($data)?$data:[]; }
function themes_save_all(array $themes): void { themes_bootstrap(); file_put_contents(themes_index_file(), json_encode(array_values($themes), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }
function themes_active(): ?array { $slug=$_COOKIE['site_theme'] ?? ''; foreach (themes_all() as $t) if (($t['slug'] ?? '')===$slug) return $t; return null; }
