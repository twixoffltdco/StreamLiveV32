<?php
declare(strict_types=1);
if (!isset($studio_title)) $studio_title = 'Студия';
if (!isset($studio_active)) $studio_active = 'dashboard';
$__sg = dirname(__DIR__, 2) . '/includes/studio_guard.php';
if (is_file($__sg) && empty($studio_skip_guard)) { require_once $__sg; studio_require_platforma_access(); }
$u = function_exists('current_user') ? current_user() : null;
$uname = $u['username'] ?? 'creator';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= htmlspecialchars($studio_title, ENT_QUOTES, 'UTF-8') ?> · Студия StreamLive</title>
<style>
:root{
  --st-bg:#0b0b12;
  --st-card:rgba(28,28,40,.85);
  --st-elev:#1c1c28;
  --st-hover:rgba(255,255,255,.06);
  --st-border:rgba(255,255,255,.08);
  --st-text:#f4f4f8;
  --st-muted:#9b9bb0;
  --st-accent:#a78bfa;
  --st-accent2:#22d3ee;
  --st-danger:#fb7185;
  --st-side:248px;
  --st-top:56px;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--st-bg)!important;color:var(--st-text)!important;
  font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;min-height:100%}
a{color:inherit;text-decoration:none}
.st-top{
  position:fixed;top:0;left:0;right:0;height:var(--st-top);z-index:60;
  display:flex;align-items:center;gap:12px;padding:0 14px;
  background:rgba(11,11,18,.9);backdrop-filter:blur(16px);border-bottom:1px solid var(--st-border);
}
.st-logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:16px}
.st-logo .mark{
  width:28px;height:28px;border-radius:8px;
  background:linear-gradient(135deg,var(--st-accent),var(--st-accent2));
  display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#0b0b12;
}
.st-logo .badge{font-size:10px;padding:2px 7px;border-radius:999px;background:var(--st-elev);color:var(--st-muted)}
.st-top-right{margin-left:auto;display:flex;align-items:center;gap:12px;font-size:13px;color:var(--st-muted)}
.st-top-right a{color:var(--st-accent2)}
.st-burger{display:none;background:transparent;border:1px solid var(--st-border);color:var(--st-text);border-radius:10px;padding:8px 10px;cursor:pointer}
.st-layout{display:flex;padding-top:var(--st-top);min-height:100vh}
.st-side{
  position:fixed;top:var(--st-top);left:0;bottom:0;width:var(--st-side);
  background:rgba(11,11,18,.95);border-right:1px solid var(--st-border);
  padding:12px 10px;overflow-y:auto;z-index:50;
}
.st-side .sec{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--st-muted);padding:14px 12px 6px}
.st-side a{
  display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:12px;
  color:var(--st-muted);margin:2px 0;font-size:14px;
}
.st-side a:hover{background:var(--st-hover);color:var(--st-text)}
.st-side a.active{background:linear-gradient(135deg,rgba(167,139,250,.18),rgba(34,211,238,.1));color:var(--st-text);font-weight:600}
.st-main{margin-left:var(--st-side);flex:1;padding:22px 24px 56px;max-width:1200px}
.st-h1{font-size:22px;font-weight:700;margin:0 0 6px}
.st-sub{color:var(--st-muted);font-size:14px;margin:0 0 20px}
.st-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.st-card{
  background:var(--st-card);border:1px solid var(--st-border);border-radius:16px;padding:16px;
  backdrop-filter:blur(12px);
}
.st-card .lbl{font-size:12px;color:var(--st-muted);margin-bottom:6px}
.st-card .val{font-size:26px;font-weight:700}
.st-panel{
  background:var(--st-card);border:1px solid var(--st-border);border-radius:16px;padding:16px 18px;margin-bottom:14px;
}
.st-panel h2{font-size:15px;font-weight:600;margin:0 0 12px}
.st-actions{display:flex;flex-wrap:wrap;gap:8px}
.btn{
  display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;
  font-weight:600;font-size:13px;border:none;cursor:pointer;background:var(--st-elev);color:var(--st-text);
}
.btn-primary{background:linear-gradient(135deg,var(--st-accent),#7c3aed);color:#fff}
.btn-outline{background:transparent;border:1px solid var(--st-border)}
.muted{color:var(--st-muted);font-size:13px;line-height:1.5}
.st-sub-row{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--st-border)}
.st-sub-row:last-child{border-bottom:0}
.st-av{width:40px;height:40px;border-radius:50%;object-fit:cover;background:#222;flex-shrink:0}
.st-av-ph{
  width:40px;height:40px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--st-accent),var(--st-accent2));
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#0b0b12;
}
.st-table{width:100%;border-collapse:collapse;font-size:13px}
.st-table th,.st-table td{padding:10px 8px;text-align:left;border-bottom:1px solid var(--st-border)}
.st-table th{color:var(--st-muted);font-weight:500}
.st-thumb{width:96px;aspect-ratio:16/9;border-radius:8px;object-fit:cover;background:#000}
@media(max-width:900px){
  .st-burger{display:inline-flex}
  .st-side{
    transform:translateX(-105%);transition:transform .2s ease;
  }
  .st-side.open{transform:translateX(0);box-shadow:8px 0 40px rgba(0,0,0,.5)}
  .st-main{margin-left:0;padding:16px 14px 48px}
  .st-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:45}
  .st-backdrop.show{display:block}
}
/* force dark — no white flashes on mobile */
input,select,textarea,button{
  background:var(--st-elev);color:var(--st-text);border:1px solid var(--st-border);border-radius:10px;
}
</style>
</head>
<body class="studio-app">
<header class="st-top">
  <button type="button" class="st-burger" id="stBurger" aria-label="Меню">☰</button>
  <a class="st-logo" href="/platforma/studio/">
    <span class="mark">SL</span>
    <span>Студия</span>
    <span class="badge">beta</span>
  </a>
  <div class="st-top-right">
    <span>@<?= htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') ?></span>
    <a href="/">На сайт</a>
  </div>
</header>
<div class="st-backdrop" id="stBackdrop"></div>
<div class="st-layout">
  <nav class="st-side" id="stSide">
    <div class="sec">Обзор</div>
    <a href="/platforma/studio/" class="<?= $studio_active==='dashboard'?'active':'' ?>">Панель</a>
    <a href="/platforma/studio/content.php" class="<?= $studio_active==='content'?'active':'' ?>">Контент</a>
    <a href="/platforma/studio/analytics.php" class="<?= $studio_active==='analytics'?'active':'' ?>">Аналитика</a>
    <a href="/platforma/studio/subscribers.php" class="<?= $studio_active==='subscribers'?'active':'' ?>">Подписчики</a>
    <div class="sec">Каналы</div>
    <a href="/platforma/studio/channel.php" class="<?= $studio_active==='channel'?'active':'' ?>">Мои каналы</a>
    <div class="sec">Публикация</div>
    <a href="/platforma/studio/schedule.php" class="<?= $studio_active==='schedule'?'active':'' ?>">Расписание и премьеры</a>
    <a href="/platforma/studio/calendar.php" class="<?= $studio_active==='calendar'?'active':'' ?>">Календарь</a>
    <a href="/platforma/studio/import.php" class="<?= $studio_active==='import'?'active':'' ?>">Импорт видео</a>
    <a href="/platforma/studio/content.php" class="<?= $studio_active==='content'?'active':'' ?>">Мои видео</a>
  </nav>
  <main class="st-main">
<?php
// end layout continues in page; close in _layout_end.php
?>
<script>
(function(){
  var b=document.getElementById('stBurger'), s=document.getElementById('stSide'), d=document.getElementById('stBackdrop');
  function close(){ s.classList.remove('open'); d.classList.remove('show'); }
  function open(){ s.classList.add('open'); d.classList.add('show'); }
  if(b) b.onclick=function(){ s.classList.contains('open')?close():open(); };
  if(d) d.onclick=close;
})();
</script>
