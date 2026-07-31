<?php
if (session_status() === PHP_SESSION_NONE) @session_start();
$mode = $_COOKIE["pl_ui_mode"] ?? $_SESSION["pl_ui_mode"] ?? "streamlife";
?><!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Платформа — оболочка StreamLife</title>
<style>
body{margin:0;font-family:system-ui,sans-serif;background:#0f0f0f;color:#f1f1f1;line-height:1.5}
.wrap{max-width:720px;margin:0 auto;padding:32px 20px}
h1{font-size:28px;margin:0 0 8px}.badge{background:#f00;color:#fff;font-size:11px;padding:2px 8px;border-radius:4px}
p{color:#aaa}.card{background:#181818;border:1px solid #303030;border-radius:12px;padding:20px;margin:20px 0}
.card h2{font-size:16px;margin:0 0 8px;color:#fff}
.btn{display:inline-block;padding:10px 18px;border-radius:18px;font-weight:500;text-decoration:none;margin:6px 6px 0 0}
.btn-r{background:#f00;color:#fff}.btn-w{background:#f1f1f1;color:#0f0f0f}.btn-o{border:1px solid #303030;color:#3ea6ff}
code{background:#212121;padding:2px 6px;border-radius:4px;font-size:13px}
ul{color:#aaa}
</style></head><body><div class="wrap">
<h1>Платформа <span class="badge">оболочка</span></h1>
<p>Это не отдельный сайт. Это <b style="color:#fff">тема оформления</b> для StreamLife: тот же движок, каналы, поиск, чат — меняется только внешний вид.</p>
<div class="card"><h2>Как переключить</h2>
<p>В шапке: <b style="color:#fff">StreamLife | Платформа</b>. Сейчас: <b style="color:#fff"><?= $mode==="platforma"?"Платформа":"StreamLife" ?></b></p>
<a class="btn btn-r" href="/platforma/switch.php?mode=platforma&redirect=/">Включить Платформу</a>
<a class="btn btn-w" href="/platforma/switch.php?mode=streamlife&redirect=/">Включить StreamLife</a>
<a class="btn btn-o" href="/">На главную</a></div>
<div class="card"><h2>Что даёт</h2>
<ul><li>Тёмный стиль YouTube / plvideo 2026</li>
<li>Все страницы StreamLife как раньше (поиск из header)</li>
<li>Рекомендации на главной (если подключён блок)</li></ul></div>
<div class="card"><h2>Подключение</h2>
<p>header.php: <code>&lt;?php @include __DIR__."/platforma/header_switcher.php"; ?&gt;</code></p>
<p>Главная: <code>&lt;?php @include __DIR__."/platforma/recommendations_block.php"; ?&gt;</code></p>
</div></div></body></html>
