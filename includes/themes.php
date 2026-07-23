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
  if (!file_exists(themes_index_file()) && file_put_contents(themes_index_file(), json_encode([], JSON_UNESCAPED_UNICODE)) === false && $strict) {
    throw new RuntimeException('Не удалось создать индекс тем.');
  }
}
function themes_slug(string $name): string {
  $slug = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
  $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
  $slug = strtr($slug, $map);
  return trim(preg_replace('/[^a-z0-9_-]+/i', '-', $slug), '-') ?: 'theme';
}
function themes_all(): array { themes_bootstrap(); themes_bootstrap_builtin(); if (!is_file(themes_index_file())) return []; $data=json_decode((string)file_get_contents(themes_index_file()), true); return is_array($data)?$data:[]; }
function themes_save_all(array $themes): void {
  themes_bootstrap(true);
  if (file_put_contents(themes_index_file(), json_encode(array_values($themes), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX) === false) throw new RuntimeException('Не удалось записать индекс тем.');
}
function themes_write_file(string $path, string $content): void {
  if (file_put_contents($path, $content, LOCK_EX) === false) throw new RuntimeException('Не удалось записать файл ' . basename($path));
}
function themes_save(string $slug, string $name, string $css, string $header, string $footer): void {
  themes_bootstrap(true);
  theme_reject_full_document($header, 'Header');
  theme_reject_full_document($footer, 'Footer');
  themes_write_file(themes_public_css_dir()."/$slug.css", $css);
  themes_write_file(themes_dir()."/$slug.header.php", $header);
  themes_write_file(themes_dir()."/$slug.footer.php", $footer);
  $themes=themes_all();
  $themes=array_values(array_filter($themes, fn($t)=>($t['slug']??'')!==$slug));
  $themes[]=['slug'=>$slug,'name'=>$name,'css'=>'/assets/themes/'.$slug.'.css','header'=>"$slug.header.php",'footer'=>"$slug.footer.php"];
  themes_save_all($themes);
}

// Header/Footer темы — это ФРАГМЕНТ, вставляемый ВНУТРЬ уже открытого <html><body> основной
// страницы (см. includes/header.php и includes/footer.php). Полный HTML-документ со своим
// <!DOCTYPE>/<html>/<head>/<body> внутри — гарантированно ломает страницу (вложенные теги,
// дублирующиеся <head>, обрыв разметки). Именно так была сломана тема FlexDev — в форму
// вставили содержимое assets/themes/header.php (это отдельный ПОЛНОСТРАНИЧНЫЙ шаблон, а не
// фрагмент). Отклоняем такой ввод сразу, вместо того чтобы молча сохранить и получить
// чёрный экран у всех, кто выберет эту тему.
function theme_reject_full_document(string $html, string $fieldLabel): void {
  if ($html === '') return;
  if (preg_match('/<!DOCTYPE|<html[\\s>]|<head[\\s>]|<body[\\s>]/i', $html)) {
    throw new RuntimeException(
      "Поле «{$fieldLabel}» содержит полный HTML-документ (<!DOCTYPE>/<html>/<head>/<body>). " .
      'Сюда нужен только фрагмент разметки для вставки ВНУТРИ уже открытого <body> сайта ' .
      '(например, свой <nav>...</nav>), а не отдельная целая страница — иначе получится ' .
      'сломанная вложенная структура и чёрный экран у всех, кто выберет эту тему.'
    );
  }
}

// Самоисцеляющаяся регистрация встроенных CSS-only тем: если рядом с этим файлом лежит
// готовый .css (например assets/themes/flexdev.css), но её ещё нет в themes.json — тема
// добавляется автоматически при следующей загрузке любой страницы, без необходимости
// вручную запускать одноразовый скрипт-регистратор (обе прежние версии таких скриптов,
// flexdev_theme.php и gitgoida_theme.php, либо никогда не запускались, либо содержали
// баги от копипасты — так что полагаться на ручной запуск нельзя).
function themes_bootstrap_builtin(): void {
  static $done = false;
  if ($done) return;
  $done = true;

  $builtins = [
    'flexdev' => 'FlexDev',
    // Добавляй сюда новые встроенные CSS-only темы: 'slug' => 'Отображаемое имя'.
    // CSS должен лежать в assets/themes/<slug>.css — остальное подключится само.
  ];

  $themes = themes_all();
  $existingSlugs = array_column($themes, 'slug');
  $changed = false;
  foreach ($builtins as $slug => $name) {
    if (in_array($slug, $existingSlugs, true)) continue;
    if (!is_file(themes_public_css_dir() . "/{$slug}.css")) continue;
    $themes[] = ['slug' => $slug, 'name' => $name, 'css' => "/assets/themes/{$slug}.css", 'header' => null, 'footer' => null];
    $changed = true;
  }
  if ($changed) {
    try { themes_save_all($themes); } catch (\Throwable $e) { /* нет прав на запись — не критично, попробуем на следующей загрузке */ }
  }
}
function themes_active(): ?array { $slug=$_COOKIE['site_theme'] ?? ''; foreach (themes_all() as $t) if (($t['slug'] ?? '')===$slug) return $t; return null; }

// Безопасное подключение фрагмента header/footer темы — перепроверяет содержимое файла
// НЕПОСРЕДСТВЕННО в момент использования (а не только при сохранении через форму). Это
// защищает и уже сохранённые ранее темы с битым header/footer (например, если на живом
// сервере уже лежит сломанная запись с полным HTML-документом внутри) — такой файл просто
// не будет подключён, вместо повторной поломки страницы у всех посетителей.
function theme_safe_include(string $filePath): void {
  if (!is_file($filePath)) return;
  $html = (string)file_get_contents($filePath);
  if (preg_match('/<!DOCTYPE|<html[\s>]|<head[\s>]|<body[\s>]/i', $html)) return;
  include $filePath;
}
