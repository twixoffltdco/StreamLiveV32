<?php
/**
 * Переключатель UI + данные пользователя для оболочки Платформы
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
if (!in_array($pl_mode, ['streamlife', 'platforma', 'telegram', 'prohub'], true)) {
  $pl_mode = 'streamlife';
}

$pl_skin = 'dark';
$pl_accent = 'red';
$pl_preset = '';
if (!empty($_COOKIE['pl_skin']) && in_array($_COOKIE['pl_skin'], ['dark', 'light'], true)) {
  $pl_skin = $_COOKIE['pl_skin'];
}
// StreamLife ☀️ toggle → same light on Platform
if (!empty($_COOKIE['site_color_mode']) && $_COOKIE['site_color_mode'] === 'light') {
  $pl_skin = 'light';
}
if (!empty($_COOKIE['pl_accent']) && in_array($_COOKIE['pl_accent'], ['red','blue','orange','green','purple','pink'], true)) {
  $pl_accent = $_COOKIE['pl_accent'];
}
if (!empty($_COOKIE['pl_preset'])) {
  $pl_preset = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$_COOKIE['pl_preset']));
}

// --- user (для сайдбара: Войти vs Подписки) ---
$pl_logged = false;
$pl_username = '';
$pl_uid = 0;
try {
  if (function_exists('current_user')) {
    $u = current_user();
    if ($u && !empty($u['id'])) {
      $pl_uid = (int)$u['id'];
      $pl_logged = true;
      $pl_username = (string)($u['username'] ?? $u['name'] ?? '');
    }
  }
  if (!$pl_logged && !empty($_SESSION['user_id'])) {
    $pl_uid = (int)$_SESSION['user_id'];
    $pl_logged = $pl_uid > 0;
    $pl_username = (string)($_SESSION['user']['username'] ?? $_SESSION['username'] ?? '');
  }
} catch (Throwable $e) {}

$pl_subs = [];
if ($pl_logged && $pl_uid > 0) {
  try {
    if (!function_exists('db')) {
      @require_once dirname(__DIR__) . '/includes/functions.php';
    }
    if (function_exists('db')) {
      // максимально простые запросы — без created_at / avatar_url / image (их может не быть)
      $queries = [
        "SELECT c.id, c.title, c.slug, c.logo_url AS avatar
         FROM favorites f
         INNER JOIN channels c ON c.id = f.channel_id
         WHERE f.user_id = ?
         LIMIT 24",
        "SELECT c.id, c.title, c.slug, c.logo_url AS avatar
         FROM channel_favorites f
         INNER JOIN channels c ON c.id = f.channel_id
         WHERE f.user_id = ?
         LIMIT 24",
        "SELECT c.id, c.title, c.slug, '' AS avatar
         FROM favorites f
         INNER JOIN channels c ON c.id = f.channel_id
         WHERE f.user_id = ?
         LIMIT 24",
      ];
      foreach ($queries as $sql) {
        try {
          $st = db()->prepare($sql);
          $st->execute([$pl_uid]);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          if ($rows) {
            $pl_subs = $rows;
            break;
          }
        } catch (Throwable $eQ) {
          // пробуем следующий вариант схемы
        }
      }
    }
  } catch (Throwable $e) {
    $pl_subs = [];
  }
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
.pl-mode-switch{display:inline-flex;align-items:center;border:1px solid rgba(255,255,255,.12);border-radius:20px;overflow:hidden;font:500 12px/1 system-ui,sans-serif;margin:0 8px;vertical-align:middle;background:rgba(24,24,24,.55);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);flex-shrink:0;z-index:50;position:relative}
.pl-mode-switch a{padding:6px 10px;color:#aaa;text-decoration:none;white-space:nowrap}
.pl-mode-switch a:hover{color:#fff;background:rgba(255,255,255,.08)}
.pl-mode-switch a.on-sl{background:#f1f1f1;color:#0f0f0f}
.pl-mode-switch a.on-pl{background:#ff0000;color:#fff}
.pl-mode-switch a.on-tg{background:#2AABEE;color:#fff}.pl-mode-switch a.on-ph{background:#3b82f6;color:#fff}
@media(max-width:700px){.pl-mode-switch a{padding:8px 10px;font-size:12px;min-height:36px;display:inline-flex;align-items:center}}
</style>
<div class="pl-mode-switch" role="navigation" aria-label="Стиль интерфейса">
  <a href="<?= htmlspecialchars($pl_sw . '?mode=streamlife&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'streamlife' ? 'on-sl' : '' ?>">StreamLife</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=platforma&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'platforma' ? 'on-pl' : '' ?>">Платформа</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=telegram&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'telegram' ? 'on-tg' : '' ?>">Telegram</a>
  <a href="<?= htmlspecialchars($pl_sw . '?mode=prohub&redirect=' . $pl_r, ENT_QUOTES, 'UTF-8') ?>" class="<?= $pl_mode === 'prohub' ? 'on-ph' : '' ?>">ProHub</a>
</div>
<?php if ($pl_mode === 'platforma'): ?>
<link rel="stylesheet" href="/platforma/theme-platforma.css?v=20260803pl20">
<?php if ($pl_skin === 'light'): ?><link rel="stylesheet" href="/assets/css/light-force.css?v=20260803lf5lf5"><?php endif; ?>
<script>
window.PL_USER = {
  logged: <?= $pl_logged ? 'true' : 'false' ?>,
  id: <?= (int)$pl_uid ?>,
  name: <?= json_encode($pl_username, JSON_UNESCAPED_UNICODE) ?>,
  subs: <?= json_encode($pl_subs ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
window.PL_TG_URL = window.PL_TG_URL || 'https://t.me/platforma_offcial';
try{
  document.documentElement.classList.add('pl-theme-platforma');
  document.documentElement.setAttribute('data-pl-skin', <?= json_encode($pl_skin) ?>);
  document.documentElement.setAttribute('data-pl-accent', <?= json_encode($pl_accent) ?>);
  <?php if ($pl_preset): ?>document.documentElement.setAttribute('data-pl-preset', <?= json_encode($pl_preset) ?>);<?php endif; ?>
  document.addEventListener('DOMContentLoaded',function(){
    if(!document.body) return;
    document.body.classList.add('pl-theme-platforma');
    document.body.setAttribute('data-pl-skin', <?= json_encode($pl_skin) ?>);
    document.body.setAttribute('data-pl-accent', <?= json_encode($pl_accent) ?>);
    <?php if ($pl_preset): ?>document.body.setAttribute('data-pl-preset', <?= json_encode($pl_preset) ?>);<?php endif; ?>
  });
}catch(e){}
</script>
<script src="/platforma/theme-platforma.js?v=20260803pl20" defer></script>
<?php elseif ($pl_mode === 'telegram'): ?>
<link rel="stylesheet" href="/platforma/theme-telegram.css?v=20260803tg3">
<?php if ($pl_skin === 'light'): ?><link rel="stylesheet" href="/assets/css/light-force.css?v=20260803lf5lf5"><?php endif; ?>
<script>
try{
  document.documentElement.classList.add('pl-theme-telegram');
  document.documentElement.setAttribute('data-pl-skin', <?= json_encode($pl_skin) ?>);
  <?php if ($pl_skin === 'light'): ?>document.documentElement.classList.add('light-mode');<?php endif; ?>
  document.addEventListener('DOMContentLoaded',function(){
    if(!document.body) return;
    document.body.classList.add('pl-theme-telegram');
    document.body.setAttribute('data-pl-skin', <?= json_encode($pl_skin) ?>);
  });
}catch(e){}
</script>
<script src="/platforma/theme-telegram.js?v=20260803tg2" defer></script>
<?php elseif ($pl_mode === 'prohub'): ?>
<link rel="stylesheet" href="/platforma/theme-prohub.css?v=2">
<script>
try{
  document.documentElement.classList.add('pl-theme-prohub');
  document.addEventListener('DOMContentLoaded',function(){document.body&&document.body.classList.add('pl-theme-prohub');});
}catch(e){}
</script>
<script src="/platforma/theme-prohub.js?v=2" defer></script>
<?php endif; ?>
