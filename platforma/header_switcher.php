<?php
/**
 * Переключатель UI: StreamLife | Платформа | Telegram
 */
try {
  if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
    @session_start();
  }
} catch (Throwable $e) { /* ignore */ }

$pl_mode = 'streamlife';
try {
  if (!empty($_COOKIE['pl_ui_mode'])) {
    $pl_mode = (string)$_COOKIE['pl_ui_mode'];
  } elseif (!empty($_SESSION['pl_ui_mode'])) {
    $pl_mode = (string)$_SESSION['pl_ui_mode'];
  }
} catch (Throwable $e) {}
if (!in_array($pl_mode, ['streamlife', 'platforma', 'telegram'], true)) {
  $pl_mode = 'streamlife';
}

$pl_redirect = '/';
try {
  if (!empty($_SERVER['REQUEST_URI'])) {
    $pl_redirect = (string)$_SERVER['REQUEST_URI'];
  }
  if (strpos($pl_redirect, 'switch.php') !== false) {
    $pl_redirect = '/';
  }
} catch (Throwable $e) {}

$pl_sw = '/platforma/switch.php';
$pl_r = rawurlencode($pl_redirect);
?>
<style>
.pl-mode-switch{display:inline-flex;align-items:center;border:1px solid #303030;border-radius:20px;overflow:hidden;font:500 12px/1 system-ui,sans-serif;margin:0 8px;vertical-align:middle;background:#181818;flex-shrink:0;z-index:50;position:relative}
.pl-mode-switch a{padding:6px 10px;color:#aaa;text-decoration:none;white-space:nowrap}
.pl-mode-switch a:hover{color:#fff;background:#272727}
.pl-mode-switch a.on-sl{background:#f1f1f1;color:#0f0f0f}
.pl-mode-switch a.on-pl{background:#ff0000;color:#fff}
.pl-mode-switch a.on-tg{background:#2AABEE;color:#fff}
@media(max-width:700px){.pl-mode-switch a{padding:6px 7px;font-size:11px}}
</style>
<div class="pl-mode-switch" role="navigation" aria-label="Стиль интерфейса">
  <a href="<?= htmlspecialchars($pl_sw . '?mode=streamlife&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'streamlife' ? 'on-sl' : '' ?>">StreamLife</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=platforma&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'platforma' ? 'on-pl' : '' ?>">Платформа</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=telegram&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'telegram' ? 'on-tg' : '' ?>">Telegram</a>
</div>
<?php if ($pl_mode === 'platforma'): ?>
<link rel="stylesheet" href="/platforma/theme-platforma.css?v=20260801yt2">
<script>
try{
  document.documentElement.classList.add('pl-theme-platforma');
  document.addEventListener('DOMContentLoaded',function(){document.body&&document.body.classList.add('pl-theme-platforma');});
}catch(e){}
</script>
<script src="/platforma/theme-platforma.js?v=20260801yt2" defer></script>
<?php elseif ($pl_mode === 'telegram'): ?>
<link rel="stylesheet" href="/platforma/theme-telegram.css?v=20260801tg1">
<script>
try{
  document.documentElement.classList.add('pl-theme-telegram');
  document.addEventListener('DOMContentLoaded',function(){document.body&&document.body.classList.add('pl-theme-telegram');});
}catch(e){}
</script>
<script src="/platforma/theme-telegram.js?v=20260801tg1" defer></script>
<?php endif; ?>
