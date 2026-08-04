<?php
/**
 * YouTube Studio — только стиль Платформа
 */
if (!isset($studio_title)) $studio_title = 'Студия';
if (!isset($studio_active)) $studio_active = 'dashboard';

if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
  @session_start();
}
$user_id = 0;
$user_name = 'Канал';
if (!empty($_SESSION['user_id'])) $user_id = (int)$_SESSION['user_id'];
if (!empty($_SESSION['user']['id'])) $user_id = (int)$_SESSION['user']['id'];
if (!empty($_SESSION['user']['username'])) $user_name = (string)$_SESSION['user']['username'];
elseif (!empty($_SESSION['username'])) $user_name = (string)$_SESSION['username'];

$__pl_mode = 'streamlife';
if (!empty($_COOKIE['pl_ui_mode'])) $__pl_mode = (string)$_COOKIE['pl_ui_mode'];
elseif (!empty($_SESSION['pl_ui_mode'])) $__pl_mode = (string)$_SESSION['pl_ui_mode'];
if ($__pl_mode !== 'platforma') {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Студия — только Платформа</title>';
  echo '<style>body{font-family:Roboto,system-ui,sans-serif;background:#0f0f0f;color:#f1f1f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:24px}.box{max-width:440px}.btn{display:inline-block;margin-top:18px;padding:14px 22px;background:#ff0000;color:#fff;border-radius:24px;text-decoration:none;font-weight:600;font-size:15px}a{color:#3ea6ff}</style></head><body><div class="box">';
  echo '<h1 style="font-size:22px;margin:0">Студия доступна только в «Платформа»</h1>';
  echo '<p style="color:#aaa;margin-top:14px;line-height:1.5">Интерфейс студии сделан в стиле Платформы. В StreamLife / Telegram студия не открывается.</p>';
  echo '<a class="btn" href="/platforma/switch.php?mode=platforma&redirect=' . rawurlencode('/platforma/studio/') . '">Включить Платформу и открыть Студию</a>';
  echo '<p style="margin-top:24px"><a href="/">← На главную</a></p></div></body></html>';
  exit;
}

$pl_skin = (!empty($_COOKIE['pl_skin']) && $_COOKIE['pl_skin'] === 'light') ? 'light' : 'dark';
$pl_accent = 'red';
if (!empty($_COOKIE['pl_accent']) && in_array($_COOKIE['pl_accent'], ['red','blue','orange','green','purple','pink'], true)) {
  $pl_accent = $_COOKIE['pl_accent'];
}
$pl_preset = !empty($_COOKIE['pl_preset']) ? preg_replace('/[^a-z0-9_-]/', '', strtolower($_COOKIE['pl_preset'])) : '';

$nav = [
  'dashboard' => ['Панель', '/platforma/studio/'],
  'content'   => ['Контент', '/platforma/studio/content.php'],
  'channel'   => ['Оформление', '/platforma/studio/channel.php'],
  'import'    => ['Импорт', '/platforma/studio/import.php'],
  'analytics' => ['Аналитика', '/platforma/studio/analytics.php'],
];
?><!DOCTYPE html>
<html lang="ru" class="pl-theme-platforma" data-pl-skin="<?= htmlspecialchars($pl_skin) ?>" data-pl-accent="<?= htmlspecialchars($pl_accent) ?>"<?= $pl_preset ? ' data-pl-preset="'.htmlspecialchars($pl_preset).'"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($studio_title) ?> — Студия</title>
<link rel="stylesheet" href="/platforma/theme-platforma.css?v=20260803pl8">
<style>
:root{
  --st-bg: var(--pl-bg, #0f0f0f);
  --st-elev: var(--pl-elev, #212121);
  --st-hover: var(--pl-hover, #272727);
  --st-card: var(--pl-elev, #181818);
  --st-border: var(--pl-border, #303030);
  --st-text: var(--pl-text, #f1f1f1);
  --st-muted: var(--pl-muted, #aaa);
  --st-accent: var(--pl-accent, #ff0000);
  --st-blue: var(--pl-link, #3ea6ff);
  --st-side: 240px;
  --st-top: 56px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:Roboto,system-ui,sans-serif;
  background:var(--st-bg)!important;
  color:var(--st-text)!important;
  min-height:100vh;
  padding:0!important;
}
a{color:inherit;text-decoration:none}
.st-top{
  position:fixed;top:0;left:0;right:0;height:var(--st-top);
  background:var(--st-bg);border-bottom:1px solid var(--st-border);
  display:flex;align-items:center;padding:0 16px;z-index:50;gap:12px;
}
.st-logo{display:flex;align-items:center;gap:10px;font-weight:500;font-size:18px}
.st-logo .icon{
  background:var(--st-accent);color:#fff;width:32px;height:22px;border-radius:6px;
  display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;
}
.st-logo .badge{font-size:10px;background:var(--st-elev);color:var(--st-muted);padding:2px 7px;border-radius:3px;text-transform:uppercase}
.st-top-right{margin-left:auto;display:flex;align-items:center;gap:14px;font-size:13px;color:var(--st-muted)}
.st-top-right a{color:var(--st-blue);font-weight:500}
.st-layout{display:flex;padding-top:var(--st-top);min-height:100vh}
.st-side{
  position:fixed;top:var(--st-top);left:0;bottom:0;width:var(--st-side);
  background:var(--st-bg);border-right:1px solid var(--st-border);
  padding:12px 8px;overflow-y:auto;
}
.st-side a{
  display:block;padding:12px 14px;border-radius:10px;font-size:14px;
  color:var(--st-muted);margin:2px 4px;
}
.st-side a:hover{background:var(--st-hover);color:var(--st-text)}
.st-side a.active{background:var(--st-elev);color:var(--st-text);font-weight:500}
.st-side .sec{font-size:12px;color:var(--st-muted);padding:16px 14px 6px;font-weight:500}
.st-main{margin-left:var(--st-side);flex:1;padding:24px 28px 48px;max-width:1100px}
.st-h1{font-size:24px;font-weight:600;margin-bottom:6px}
.st-sub{color:var(--st-muted);font-size:14px;margin-bottom:22px}
.st-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.st-card{background:var(--st-card);border:1px solid var(--st-border);border-radius:14px;padding:18px}
.st-card .lbl{font-size:12px;color:var(--st-muted);margin-bottom:6px}
.st-card .val{font-size:26px;font-weight:600}
.st-panel{background:var(--st-card);border:1px solid var(--st-border);border-radius:14px;padding:18px;margin-bottom:14px}
.st-panel h2{font-size:15px;font-weight:600;margin-bottom:12px}
.st-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
.btn{display:inline-flex;align-items:center;padding:11px 18px;border-radius:20px;font-weight:500;font-size:14px;border:none;cursor:pointer}
.btn-blue{background:var(--st-blue);color:#0f0f0f}
.btn-white{background:var(--st-text);color:var(--st-bg)}
.btn-outline{background:transparent;color:var(--st-text);border:1px solid var(--st-border)}
.btn-red{background:var(--st-accent);color:#fff}
.muted{color:var(--st-muted);font-size:13px;line-height:1.5}
.alert{padding:12px 16px;border-radius:10px;background:var(--st-elev);margin-bottom:14px;font-size:14px}
.alert-info{border-left:3px solid var(--st-blue)}
label.f{display:block;font-size:13px;color:var(--st-muted);margin:12px 0 6px}
input.f,textarea.f,select.f{
  width:100%;max-width:520px;background:var(--st-bg);border:1px solid var(--st-border);
  border-radius:10px;color:var(--st-text);padding:11px 12px;font:inherit;
}
table.st-table{width:100%;border-collapse:collapse;font-size:14px}
table.st-table th{text-align:left;color:var(--st-muted);font-weight:500;padding:12px 8px;border-bottom:1px solid var(--st-border)}
table.st-table td{padding:12px 8px;border-bottom:1px solid var(--st-border)}
@media(max-width:800px){
  .st-side{display:none}
  .st-main{margin-left:0;padding:16px}
}
</style>
</head>
<body class="pl-theme-platforma" data-pl-skin="<?= htmlspecialchars($pl_skin) ?>" data-pl-accent="<?= htmlspecialchars($pl_accent) ?>"<?= $pl_preset ? ' data-pl-preset="'.htmlspecialchars($pl_preset).'"' : '' ?>>
<header class="st-top">
  <a href="/platforma/studio/" class="st-logo">
    <span class="icon">▶</span>
    <span>Студия</span>
    <span class="badge">Платформа</span>
  </a>
  <div class="st-top-right">
    <span><?= htmlspecialchars($user_name) ?></span>
    <a href="/videos">Видео</a>
    <a href="/">На сайт</a>
  </div>
</header>
<div class="st-layout">
  <aside class="st-side">
    <?php foreach ($nav as $key => $item): ?>
      <a href="<?= htmlspecialchars($item[1]) ?>" class="<?= $studio_active === $key ? 'active' : '' ?>"><?= htmlspecialchars($item[0]) ?></a>
    <?php endforeach; ?>
    <div class="sec">Сайт</div>
    <a href="/">Главная</a>
    <a href="/videos">Каталог видео</a>
    <a href="/catalog.php">Каналы</a>
  </aside>
  <main class="st-main">
