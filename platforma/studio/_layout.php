<?php
/**
 * Общий каркас YouTube Studio для Platforma
 * Переменные: $studio_title, $studio_active (dashboard|content|channel|import|analytics)
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

$nav = [
    'dashboard' => ['Студия', '/platforma/studio/'],
    'content'   => ['Контент', '/platforma/studio/content.php'],
    'channel'   => ['Оформление канала', '/platforma/studio/channel.php'],
    'import'    => ['Импорт видео', '/platforma/studio/import.php'],
    'analytics' => ['Аналитика', '/platforma/studio/analytics.php'],
];
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($studio_title) ?> — YouTube Studio стиль</title>
<style>
:root{--bg:#0f0f0f;--elev:#212121;--hover:#272727;--card:#181818;--border:#303030;--text:#f1f1f1;--muted:#aaa;--blue:#3ea6ff;--red:#f00;--side:240px;--top:56px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Roboto,system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
a{color:inherit;text-decoration:none}
.st-top{position:fixed;top:0;left:0;right:0;height:var(--top);background:var(--bg);border-bottom:1px solid #222;display:flex;align-items:center;padding:0 16px;z-index:50;gap:12px}
.st-logo{display:flex;align-items:center;gap:8px;font-weight:500;font-size:18px}
.st-logo span.icon{background:var(--red);color:#fff;width:28px;height:20px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:11px}
.st-logo .badge{font-size:10px;background:var(--elev);color:var(--muted);padding:2px 6px;border-radius:3px}
.st-top-right{margin-left:auto;display:flex;align-items:center;gap:12px;font-size:13px;color:var(--muted)}
.st-top-right a{color:var(--blue)}
.st-layout{display:flex;padding-top:var(--top);min-height:100vh}
.st-side{position:fixed;top:var(--top);left:0;bottom:0;width:var(--side);background:var(--bg);border-right:1px solid #222;padding:12px;overflow-y:auto}
.st-side a{display:block;padding:10px 14px;border-radius:8px;font-size:14px;color:var(--muted);margin-bottom:2px}
.st-side a:hover{background:var(--hover);color:var(--text)}
.st-side a.active{background:var(--elev);color:var(--text);font-weight:500}
.st-side .sec{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);padding:16px 14px 6px}
.st-main{margin-left:var(--side);flex:1;padding:24px 32px 48px;max-width:1100px}
.st-h1{font-size:24px;font-weight:500;margin-bottom:8px}
.st-sub{color:var(--muted);font-size:14px;margin-bottom:24px}
.st-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:24px}
.st-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px}
.st-card .lbl{font-size:13px;color:var(--muted);margin-bottom:8px}
.st-card .val{font-size:28px;font-weight:500}
.st-panel{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:16px}
.st-panel h2{font-size:16px;font-weight:500;margin-bottom:12px}
.st-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
.btn{display:inline-flex;align-items:center;padding:10px 18px;border-radius:18px;font-weight:500;font-size:14px;border:none;cursor:pointer}
.btn-blue{background:var(--blue);color:#0f0f0f}
.btn-white{background:#f1f1f1;color:#0f0f0f}
.btn-outline{background:transparent;color:var(--blue);border:1px solid var(--border)}
.btn-outline:hover{border-color:var(--blue)}
table.st-table{width:100%;border-collapse:collapse;font-size:14px}
table.st-table th{text-align:left;color:var(--muted);font-weight:500;padding:12px 8px;border-bottom:1px solid var(--border)}
table.st-table td{padding:12px 8px;border-bottom:1px solid var(--border);vertical-align:middle}
table.st-table tr:hover td{background:var(--hover)}
label.f{display:block;font-size:13px;color:var(--muted);margin:12px 0 6px}
input.f,textarea.f,select.f{width:100%;max-width:480px;background:#121212;border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px 12px;font:inherit}
textarea.f{min-height:100px;resize:vertical}
.muted{color:var(--muted);font-size:13px}
.alert{padding:12px 16px;border-radius:8px;background:var(--elev);margin-bottom:16px;font-size:14px}
.alert-ok{border-left:3px solid #2ba640}
.alert-info{border-left:3px solid var(--blue)}
@media(max-width:900px){
  .st-side{transform:translateX(-100%);z-index:40}
  .st-side.open{transform:translateX(0)}
  .st-main{margin-left:0;padding:16px}
}
</style>
</head>
<body>
<header class="st-top">
  <a href="/platforma/studio/" class="st-logo">
    <span class="icon">▶</span>
    <span>Студия канала</span>
    <span class="badge">Бета</span>
  </a>
  <div class="st-top-right">
    <span><?= htmlspecialchars($user_name) ?></span>
    <a href="/">← На сайт</a>
    <a href="/platforma/switch.php?mode=streamlife&redirect=/">StreamLife</a>
  </div>
</header>
<div class="st-layout">
  <aside class="st-side" id="st-side">
    <?php foreach ($nav as $key => $item): ?>
      <a href="<?= htmlspecialchars($item[1]) ?>" class="<?= $studio_active === $key ? 'active' : '' ?>"><?= htmlspecialchars($item[0]) ?></a>
      <?php if ($key === 'dashboard'): ?><div class="sec">Канал</div><?php endif; ?>
    <?php endforeach; ?>
    <div class="sec">StreamLife</div>
    <a href="/dashboard.php" target="_blank">Старый dashboard →</a>
    <a href="/channel_manage.php" target="_blank">Старый channel_manage →</a>
    <a href="/video_import.php" target="_blank">Старый video_import →</a>
  </aside>
  <main class="st-main">
