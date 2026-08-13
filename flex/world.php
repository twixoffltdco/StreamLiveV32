<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
require_once dirname(__DIR__) . '/includes/flex_world.php';
flex_world_ensure();

$u = current_user();
if (!$u) {
  if (function_exists('flash_set')) flash_set('error', 'Войдите для Flex World');
  redirect('/auth/login.php?redirect=' . urlencode('/flex/world.php'));
}

$servers = flex_world_servers();
$allFull = true;
foreach ($servers as $s) { if (!$s['full']) { $allFull = false; break; } }
$staff = flex_world_is_staff($u);
$uid = (int)$u['id'];

// Flex World доступен ТОЛЬКО в стиле FLEX (pl_ui_mode=flex)
$uiMode = strtolower(trim((string)($_COOKIE['pl_ui_mode'] ?? $_SESSION['pl_ui_mode'] ?? '')));
if ($uiMode !== 'flex') {
  $pageTitle = 'Flex World';
  // если header ещё не подключён — лёгкий редирект
  if (!headers_sent()) {
    header('Location: /platforma/switch.php?mode=flex&redirect=' . rawurlencode('/flex/world.php'));
    exit;
  }
  echo '<div class="container" style="padding:24px"><p>Flex World открывается только в стиле <b>FLEX</b>.</p>';
  echo '<p><a href="/platforma/switch.php?mode=flex&redirect=/flex/world.php" style="display:inline-block;padding:10px 16px;background:#00a2ff;color:#fff;border-radius:10px;font-weight:800;text-decoration:none">Включить FLEX и войти</a></p></div>';
  exit;
}
$role = (string)($u['role'] ?? 'user');
$crown = flex_world_has_crown($uid);

// queue actions
if (isset($_GET['queue']) && $allFull && !$staff) {
  try {
    $cnt = (int)db()->query('SELECT COUNT(*) FROM flex_world_queue')->fetchColumn();
    if ($cnt >= 5000) {
      $pageTitle = 'Flex World';
      require dirname(__DIR__) . '/includes/header.php';
      echo '<div class="container" style="padding:24px"><p>Очередь временно перегружена. Попробуйте позже или <a href="/platforma/switch.php?mode=platforma&redirect=/">Платформу</a>.</p></div>';
      require dirname(__DIR__) . '/includes/footer.php';
      exit;
    }
    db()->prepare(
      'INSERT INTO flex_world_queue (user_id, username, joined_at) VALUES (?,?,NOW())
       ON DUPLICATE KEY UPDATE username=VALUES(username)'
    )->execute([$uid, (string)$u['username']]);
  } catch (Throwable $e) {}
  redirect('/flex/world.php?waiting=1');
}
if (isset($_GET['leave_queue'])) {
  try { db()->prepare('DELETE FROM flex_world_queue WHERE user_id=?')->execute([$uid]); } catch (Throwable $e) {}
  redirect('/flex/world.php');
}

// auto-admit from queue when free slot
if ($allFull === false) {
  try {
    $q = db()->query('SELECT user_id FROM flex_world_queue ORDER BY joined_at ASC LIMIT 1')->fetch();
    if ($q && (int)$q['user_id'] === $uid) {
      db()->prepare('DELETE FROM flex_world_queue WHERE user_id=?')->execute([$uid]);
    }
  } catch (Throwable $e) {}
}

$qpos = 0;
try {
  $st = db()->prepare('SELECT 1 FROM flex_world_queue WHERE user_id=?');
  $st->execute([$uid]);
  if ($st->fetchColumn()) $qpos = flex_world_queue_pos($uid);
} catch (Throwable $e) {}

$srv = (int)($_GET['server'] ?? 0);
$mapId = max(1, min(100, (int)($_GET['map'] ?? 0)));
if ($mapId < 1) $mapId = random_int(1, 100);

if ($srv >= 1 && $srv <= 10) {
  if ($allFull && !$staff) {
    redirect('/flex/world.php?queue=1');
  }
  foreach ($servers as $s) {
    if ((int)$s['id'] === $srv && $s['full'] && !$staff) {
      if (function_exists('flash_set')) flash_set('error', 'Сервер заполнен');
      redirect('/flex/world.php');
    }
  }
  // IP bind
  $ipHash = flex_world_ip_hash();
  try {
    $st = db()->prepare('SELECT user_id FROM flex_world_meta WHERE ip_hash=? AND user_id<>? LIMIT 1');
    $st->execute([$ipHash, $uid]);
    if ($st->fetchColumn()) {
      $pageTitle = 'Flex World';
      require dirname(__DIR__) . '/includes/header.php';
      echo '<div class="container" style="padding:24px"><p>С этого IP уже привязан другой аккаунт.</p></div>';
      require dirname(__DIR__) . '/includes/footer.php';
      exit;
    }
    db()->prepare(
      'INSERT INTO flex_world_meta (user_id, ip_hash) VALUES (?,?)
       ON DUPLICATE KEY UPDATE ip_hash=IF(ip_hash="" OR ip_hash=VALUES(ip_hash), VALUES(ip_hash), ip_hash)'
    )->execute([$uid, $ipHash]);
  } catch (Throwable $e) {}

  try { db()->prepare('DELETE FROM flex_world_queue WHERE user_id=?')->execute([$uid]); } catch (Throwable $e) {}

  $av = flex_avatar_get($uid);
  $pageTitle = 'Flex World · S' . $srv . ' · Map ' . $mapId;
  // Minimal header for game (no site chrome scrolling into phone)
  ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<title><?= htmlspecialchars($pageTitle) ?></title>
<style>
  html,body{margin:0;padding:0;height:100%;overflow:hidden;background:#0b0e14;font-family:system-ui,-apple-system,sans-serif;touch-action:none}
  #fxw-root{position:relative;width:100%;height:100vh;height:100dvh;background:#0b0e14;overflow:hidden}
  #fxw-canvas{width:100%;height:100%}
  .fx-badge{background:rgba(0,0,0,.55);color:#fff;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700}
  .fx-btn{border:0;border-radius:10px;padding:6px 12px;font-weight:800;font-size:12px;cursor:pointer;color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
  /* Phone overlay — real phone UI */
  #fx-phone{
    display:none;position:absolute;inset:0;z-index:40;background:rgba(0,0,0,.72);
    align-items:center;justify-content:center;padding:12px;touch-action:manipulation
  }
  #fx-phone.open{display:flex}
  #fx-phone-shell{
    width:min(380px,94vw);height:min(720px,88vh);background:#0a0a0c;
    border-radius:28px;border:3px solid #222;box-shadow:0 20px 60px rgba(0,0,0,.7);
    display:flex;flex-direction:column;overflow:hidden;position:relative
  }
  #fx-phone-notch{height:28px;background:#111;display:flex;align-items:center;justify-content:center;flex-shrink:0}
  #fx-phone-notch span{width:90px;height:8px;background:#222;border-radius:8px}
  #fx-phone-tabs{display:flex;background:#111;border-bottom:1px solid #222;flex-shrink:0;overflow-x:auto}
  #fx-phone-tabs button{
    flex:1;min-width:64px;border:0;background:transparent;color:#94a3b8;padding:10px 6px;
    font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap
  }
  #fx-phone-tabs button.active{color:#00a2ff;border-bottom:2px solid #00a2ff}
  #fx-phone-frame{flex:1;border:0;width:100%;background:#000;touch-action:auto}
  #fx-phone-close{
    position:absolute;top:6px;right:10px;z-index:2;width:32px;height:32px;border:0;
    border-radius:50%;background:rgba(255,255,255,.12);color:#fff;font-size:16px;cursor:pointer
  }
  /* Weapon HUD */
  #fx-weapons{
    position:absolute;left:50%;bottom:14px;transform:translateX(-50%);z-index:22;
    display:flex;gap:6px;pointer-events:auto
  }
  #fx-weapons button{
    width:52px;height:52px;border:2px solid rgba(255,255,255,.2);border-radius:12px;
    background:rgba(0,0,0,.55);color:#fff;font-size:20px;cursor:pointer;font-weight:800
  }
  #fx-weapons button.active{border-color:#00a2ff;background:rgba(0,162,255,.25)}
  /* HP bar */
  #fx-hp{
    position:absolute;left:12px;top:52px;z-index:22;width:140px;height:14px;
    background:rgba(0,0,0,.5);border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,.15)
  }
  #fx-hp-fill{height:100%;width:100%;background:linear-gradient(90deg,#22c55e,#16a34a);transition:width .2s}
  /* Death overlay */
  #fx-death{
    display:none;position:absolute;inset:0;z-index:60;background:rgba(0,0,0,.9);
    color:#fff;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;padding:20px
  }
  #fx-death.show{display:flex}
  #fx-death h2{margin:0;font-size:28px;font-weight:900}
  #fx-death p{margin:0;color:#94a3b8;font-size:15px}
  #fx-death a,#fx-death button{
    padding:12px 20px;border-radius:12px;border:0;font-weight:800;font-size:14px;
    cursor:pointer;text-decoration:none;color:#fff
  }
  /* Name tag prefixes (Arizona-style) */
  .fx-prefix-admin{background:#dc2626;color:#fff;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:800;margin-right:4px}
  .fx-prefix-mod{background:#2563eb;color:#fff;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:800;margin-right:4px}
  #fxw-npc-talk{
    display:none;position:absolute;left:50%;bottom:80px;transform:translateX(-50%);z-index:26;
    max-width:min(360px,92vw);background:rgba(15,20,30,.95);border:1px solid #00a2ff55;
    border-radius:14px;padding:12px 14px;color:#fff;font-size:13px
  }
  #fxw-phone-peek{
    display:none;position:absolute;right:12px;bottom:80px;z-index:25;width:min(280px,50vw);
    background:#0a0a0c;border:2px solid #333;border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.5)
  }
  #fxw-hint{position:absolute;left:10px;bottom:10px;z-index:15;background:rgba(0,0,0,.5);color:#fff;padding:6px 10px;border-radius:8px;font-size:11px}
  #fx-crosshair{
    position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);
    width:18px;height:18px;z-index:20;pointer-events:none;
  }
  #fx-crosshair:before,#fx-crosshair:after{
    content:"";position:absolute;background:rgba(255,255,255,.9);
    box-shadow:0 0 2px #000;
  }
  #fx-crosshair:before{left:8px;top:0;width:2px;height:18px}
  #fx-crosshair:after{left:0;top:8px;width:18px;height:2px}
  #fx-anim-bar{
    position:absolute;left:50%;bottom:90px;transform:translateX(-50%);
    z-index:22;display:none;gap:6px;flex-wrap:wrap;justify-content:center;max-width:92vw;
  }
  #fx-anim-bar.show{display:flex}
  #fx-anim-bar button{
    border:0;border-radius:12px;padding:10px 12px;background:rgba(0,0,0,.55);color:#fff;
    font-weight:800;font-size:12px;pointer-events:auto;
  }
</style>
</head>
<body>
<div id="fxw-root">
  <div style="position:absolute;left:0;right:0;top:0;z-index:20;display:flex;flex-wrap:wrap;gap:8px;padding:8px 10px;background:linear-gradient(180deg,rgba(0,0,0,.55),transparent);pointer-events:none">
    <span class="fx-badge" style="background:#00a2ff;pointer-events:auto">Flex</span>
    <span id="fxw-clock" class="fx-badge"></span>
    <span id="fxw-online" class="fx-badge">1/10</span>
    <span class="fx-badge">Карта <?= (int)$mapId ?>/100 · S<?= (int)$srv ?></span>
    <a href="/flex/avatar.php" class="fx-btn" style="background:rgba(255,255,255,.12);pointer-events:auto">Персонаж</a>
    <a href="/flex/world.php" class="fx-btn" style="margin-left:auto;background:#e74c3c;pointer-events:auto">Leave</a>
  </div>
  <div id="fx-hp"><div id="fx-hp-fill"></div></div>
  <div id="fx-crosshair" aria-hidden="true"></div>
  <div id="fx-anim-bar">
    <button type="button" data-anim="wave">👋</button>
    <button type="button" data-anim="sit">🪑</button>
    <button type="button" data-anim="dance">💃</button>
    <button type="button" data-anim="clap">👏</button>
    <button type="button" data-anim="lay">🛌</button>
    <button type="button" data-anim="">⏹</button>
  </div>
  <div id="fxw-canvas"></div>
  <div id="fx-weapons">
    <button type="button" data-w="0" class="active" title="Кулаки">✊</button>
    <button type="button" data-w="1" title="Бита">🏏</button>
    <button type="button" data-w="2" title="Пистолет">🔫</button>
  </div>
  <div id="fxw-phone-peek">
    <div style="padding:6px 8px;font-size:11px;color:#94a3b8;font-weight:700" id="fxw-peek-title">Телефон</div>
    <iframe id="fxw-peek-frame" style="width:100%;height:220px;border:0;background:#000" sandbox="allow-scripts allow-same-origin"></iframe>
  </div>
  <div id="fxw-npc-talk"></div>
  <div id="fxw-hint">WASD · P телефон · 1-3 оружие · B/N блоки · G/H/J/K/L аним · E NPC</div>

  <!-- Real phone UI -->
  <div id="fx-phone">
    <div id="fx-phone-shell">
      <button type="button" id="fx-phone-close" aria-label="Закрыть">✕</button>
      <div id="fx-phone-notch"><span></span></div>
      <div id="fx-phone-tabs">
        <button type="button" data-tab="videos" class="active">Видео</button>
        <button type="button" data-tab="channels">Каналы</button>
        <button type="button" data-tab="forum">Форум</button>
        <button type="button" data-tab="resources">Ресурсы</button>
        <button type="button" data-tab="profile">Профиль</button>
        <button type="button" data-tab="rating">Рейтинг</button>
      </div>
      <iframe id="fx-phone-frame" src="about:blank" sandbox="allow-scripts allow-same-origin allow-forms allow-popups"></iframe>
    </div>
  </div>

  <!-- Death screen -->
  <div id="fx-death">
    <h2>Вас убили</h2>
    <p id="fx-death-msg">Ник убийцы</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center">
      <button type="button" id="fx-respawn" style="background:#00a2ff">Возродиться</button>
      <a href="/flex/world.php" style="background:#334155">К серверам</a>
      <a href="/platforma/switch.php?mode=platforma&redirect=/" style="background:#e74c3c">Платформа</a>
    </div>
  </div>
</div>
<script>
window.__FXW__ = {
  server: <?= (int)$srv ?>,
  map: <?= (int)$mapId ?>,
  user: <?= json_encode(['id'=>$uid,'name'=>$u['username'],'role'=>$role], JSON_UNESCAPED_UNICODE) ?>,
  crown: <?= $crown ? 'true' : 'false' ?>,
  avatar: <?= json_encode($av, JSON_UNESCAPED_UNICODE) ?>,
  staff: <?= $staff ? 'true' : 'false' ?>,
  role: <?= json_encode($role, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
<script src="/assets/js/flex-world.js?v=14"></script>
</body>
</html>
<?php
  exit;
}

$pageTitle = 'Flex World';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="container" style="max-width:720px;padding:20px 16px">
  <h1 style="margin:0 0 8px">Flex World</h1>
  <p style="color:#888;font-size:14px">100 карт · 10 серверов × 10 игроков · телефон · NPC · оружие · префиксы. <a href="/flex/avatar.php">Настроить персонажа</a></p>
  <?php if ($qpos > 0): ?>
    <div style="background:#1e3a5f;color:#dbeafe;padding:14px;border-radius:12px;margin:12px 0">
      Вы в очереди: <b>#<?= (int)$qpos ?></b>. Обновите страницу, когда освободится слот.
      <a href="?leave_queue=1" style="color:#fff;margin-left:8px">Выйти из очереди</a>
    </div>
  <?php elseif ($allFull && !$staff): ?>
    <div style="background:#3f1d1d;color:#fecaca;padding:14px;border-radius:12px;margin:12px 0">
      Все сервера заняты.
      <a href="?queue=1" style="color:#fff;font-weight:800">Встать в очередь</a>
      или <a href="/platforma/switch.php?mode=platforma&redirect=/" style="color:#fff">Платформа</a>
    </div>
  <?php elseif ($allFull && $staff): ?>
    <div style="background:#14532d;color:#bbf7d0;padding:10px;border-radius:10px;margin:12px 0">Staff: очередь не действует — выбирайте сервер.</div>
  <?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px">
    <?php foreach ($servers as $s): ?>
      <?php if ($s['full'] && !$staff): ?>
        <div style="padding:14px;border-radius:12px;background:#222;opacity:.55;text-align:center"><b>S<?= (int)$s['id'] ?></b><br><span style="font-size:12px;color:#f87171"><?= (int)$s['players'] ?>/10</span></div>
      <?php else: ?>
        <a href="?server=<?= (int)$s['id'] ?>&map=<?= random_int(1,100) ?>" style="padding:14px;border-radius:12px;background:#151a24;border:1px solid #00a2ff55;text-align:center;text-decoration:none;color:#fff">
          <b>Server <?= (int)$s['id'] ?></b><br><span style="font-size:12px;color:#94a3b8"><?= (int)$s['players'] ?>/10<?= $s['full']?' FULL':'' ?></span>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($qpos > 0): ?>
<script>
(function(){
  function tick(){
    fetch('/api/flex_queue.php',{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d||!d.ok) return;
      if(d.pos===0 || d.free_servers>0){
        if(d.pos===0) location.href='/flex/world.php';
      }
    }).catch(function(){});
  }
  setInterval(tick, 8000);
})();
</script>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
