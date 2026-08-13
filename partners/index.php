<?php
$pageTitle = 'StreamLive партнёры';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/partners.php';
require_login();
partners_ensure_schema();
partners_remember_ref_from_request();

// Только личный аккаунт
if (!empty($_SESSION['brand_act_as'])) {
  flash_set('error', 'Партнёрка только с личного аккаунта — переключитесь с бренда');
  redirect('/brands/switch.php?to=0&redirect=' . rawurlencode('/partners/'));
}

$me = current_user();
$realId = (int)($me['id'] ?? 0);
if (!empty($me['is_brand'])) {
  flash_set('error', 'Только личный аккаунт');
  redirect('/');
}

$promo = partners_get_promo($realId);
$stats = partners_stats($realId);
$sub = partners_active_sub($realId);
$tab = (string)($_GET['tab'] ?? 'cabinet');
$chartDays = (int)($_GET['days'] ?? 30);
if (!in_array($chartDays, [30, 60, 100, 365], true)) $chartDays = 30;
$chartData = ($promo && $tab !== 'activate') ? partners_activations_by_day($realId, $chartDays) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) {
    try { csrf_verify(); } catch (Throwable $e) {}
  }
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'create_promo') {
    $res = partners_create_promo($realId, (string)($_POST['code'] ?? ''));
    flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? ('Промокод «' . $res['code'] . '» создан навсегда') : ($res['error'] ?? 'Ошибка'));
    redirect('/partners/');
  }

  if ($action === 'activate') {
    $res = partners_activate($realId, (string)($_POST['code'] ?? ''));
    if (!empty($res['ok'])) {
      flash_set('success', 'Подписка активна до ' . $res['access_until'] . ' (' . (int)$res['days'] . ' дн.). Лимит брендов до 50.');
    } else {
      flash_set('error', $res['error'] ?? 'Ошибка');
    }
    redirect('/partners/?tab=activate');
  }
}

require_once dirname(__DIR__) . '/includes/header.php';
$flash = function_exists('flash_get') ? flash_get() : [];
?>
<style>
.p-wrap{max-width:720px;margin:24px auto;padding:0 16px}
.p-wrap h1{font-size:22px;font-weight:900;margin:0 0 8px}
.p-wrap .sub{color:var(--text-dim,#94a3b8);margin:0 0 16px;line-height:1.5}
.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.tabs a{padding:8px 12px;border-radius:10px;text-decoration:none;font-weight:700;font-size:13px;background:#1e293b;color:#fff}
.tabs a.on{background:#a78bfa;color:#0b0e14}
.form-card{background:var(--card,#1e293b);border-radius:14px;padding:16px;margin-top:12px}
.form-card label{display:block;font-size:13px;margin:10px 0 4px}
.form-card input{width:100%;padding:10px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#fff;box-sizing:border-box}
.stat{display:inline-block;margin:6px 12px 6px 0;padding:10px 14px;border-radius:12px;background:#0f172a;border:1px solid #334155}
.stat b{display:block;font-size:20px}
.stat span{font-size:12px;color:#94a3b8}
.btn-p{display:inline-block;padding:10px 14px;border-radius:10px;font-weight:800;border:0;cursor:pointer;background:#a78bfa;color:#0b0e14;margin-top:12px}
.code-box{font-family:monospace;font-size:18px;letter-spacing:1px;padding:12px;background:#0f172a;border-radius:10px;margin:8px 0}
</style>
<div class="p-wrap">
  <h1>Партнёры StreamLive</h1>
  <p class="sub">Личный промокод навсегда · активации · рефералы. Администрация код не отклоняет и не отзывает.</p>

  <div class="tabs">
    <a class="<?= $tab !== 'activate' ? 'on' : '' ?>" href="/partners/">Кабинет</a>
    <a class="<?= $tab === 'activate' ? 'on' : '' ?>" href="/partners/?tab=activate">Активировать код</a>
    <a href="/partners_welcome.php">О программе</a>
  </div>

  <?php foreach ($flash as $type => $msg): ?>
    <p style="padding:10px;border-radius:10px;background:<?= $type==='error'?'#450a0a':'#052e16' ?>"><?= e(is_array($msg)?implode(', ',$msg):(string)$msg) ?></p>
  <?php endforeach; ?>

  <?php if ($sub): ?>
    <div class="form-card" style="border:1px solid rgba(167,139,250,.35)">
      <b>Ваша подписка активна</b>
      <p class="sub" style="margin:6px 0 0">до <b><?= e($sub['access_until']) ?></b> · код <?= e($sub['code'] ?? '') ?> · лимит брендов до 50</p>
    </div>
  <?php endif; ?>

  <?php if ($tab === 'activate'): ?>
    <div class="form-card">
      <h2 style="margin:0 0 8px;font-size:16px">Активировать партнёрский промокод</h2>
      <p class="sub">Только с личного аккаунта. Один раз на код. Свой код активировать нельзя.</p>
      <form method="post">
        <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
        <input type="hidden" name="action" value="activate">
        <label>Промокод</label>
        <input name="code" required maxlength="16" style="text-transform:uppercase" placeholder="XXXX">
        <button class="btn-p" type="submit">Активировать</button>
      </form>
    </div>
  <?php else: ?>
    <div class="form-card">
      <h2 style="margin:0 0 8px;font-size:16px">Ваш промокод</h2>
      <?php if ($promo): ?>
        <div class="code-box"><?= e($promo['code']) ?></div>
        <p class="sub">Создан <?= e($promo['created_at'] ?? '') ?>. Переименовать <b>нельзя</b>.</p>
        <p class="sub">Реферальная ссылка:</p>
        <div class="code-box" style="font-size:13px;word-break:break-all"><?= e(partners_referral_link($promo['code'])) ?></div>
        <div style="margin-top:12px">
          <div class="stat"><b><?= (int)$stats['activations'] ?></b><span>активаций всего</span></div>
          <div class="stat"><b><?= (int)$stats['active_now'] ?></b><span>активных сейчас</span></div>
          <div class="stat"><b><?= (int)$stats['referrals'] ?></b><span>рефералов</span></div>
        </div>
        <div style="margin-top:18px">
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;align-items:center">
            <b style="font-size:14px;margin-right:6px">Динамика активаций</b>
            <?php foreach ([30,60,100,365] as $d): ?>
              <a href="/partners/?days=<?= $d ?>" style="padding:5px 10px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;background:<?= $chartDays===$d?'#a78bfa':'#334155' ?>;color:<?= $chartDays===$d?'#0b0e14':'#fff' ?>"><?= $d === 365 ? 'год' : $d.' дн.' ?></a>
            <?php endforeach; ?>
          </div>
          <canvas id="partnerActChart" width="680" height="200" style="width:100%;max-width:680px;height:200px;background:#0f172a;border-radius:12px"></canvas>
          <script>
          (function(){
            var data = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
            var c = document.getElementById('partnerActChart');
            if (!c || !data || !data.length) return;
            var ctx = c.getContext('2d');
            var W = c.width, H = c.height;
            var pad = {t:16,r:12,b:28,l:36};
            var max = 1;
            data.forEach(function(x){ if (x.count > max) max = x.count; });
            var n = data.length;
            var plotW = W - pad.l - pad.r, plotH = H - pad.t - pad.b;
            ctx.fillStyle = '#0f172a'; ctx.fillRect(0,0,W,H);
            // grid
            ctx.strokeStyle = 'rgba(148,163,184,.15)'; ctx.lineWidth = 1;
            for (var g=0;g<=4;g++){
              var y = pad.t + plotH * (g/4);
              ctx.beginPath(); ctx.moveTo(pad.l,y); ctx.lineTo(W-pad.r,y); ctx.stroke();
              ctx.fillStyle = '#64748b'; ctx.font = '10px system-ui'; ctx.textAlign = 'right';
              ctx.fillText(String(Math.round(max * (1-g/4))), pad.l-4, y+3);
            }
            // line
            ctx.beginPath();
            ctx.strokeStyle = '#a78bfa'; ctx.lineWidth = 2;
            data.forEach(function(x,i){
              var px = pad.l + (n===1?plotW/2:(i/(n-1))*plotW);
              var py = pad.t + plotH * (1 - x.count/max);
              if (i===0) ctx.moveTo(px,py); else ctx.lineTo(px,py);
            });
            ctx.stroke();
            // dots + fill
            ctx.fillStyle = 'rgba(167,139,250,.15)';
            ctx.beginPath();
            data.forEach(function(x,i){
              var px = pad.l + (n===1?plotW/2:(i/(n-1))*plotW);
              var py = pad.t + plotH * (1 - x.count/max);
              if (i===0) ctx.moveTo(px,py); else ctx.lineTo(px,py);
            });
            var lastX = pad.l + (n===1?plotW/2:plotW);
            ctx.lineTo(lastX, pad.t+plotH); ctx.lineTo(pad.l, pad.t+plotH); ctx.closePath(); ctx.fill();
            ctx.fillStyle = '#a78bfa';
            data.forEach(function(x,i){
              var px = pad.l + (n===1?plotW/2:(i/(n-1))*plotW);
              var py = pad.t + plotH * (1 - x.count/max);
              ctx.beginPath(); ctx.arc(px,py,2.5,0,Math.PI*2); ctx.fill();
            });
            // x labels sparse
            ctx.fillStyle = '#64748b'; ctx.font = '10px system-ui'; ctx.textAlign = 'center';
            var step = Math.max(1, Math.floor(n/6));
            for (var i=0;i<n;i+=step){
              var px = pad.l + (n===1?plotW/2:(i/(n-1))*plotW);
              var lab = (data[i].date||'').slice(5); // MM-DD
              ctx.fillText(lab, px, H-8);
            }
          })();
          </script>
        </div>
      <?php else: ?>
        <p class="sub">Создайте код один раз — он останется с вами навсегда.</p>
        <form method="post">
          <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
          <input type="hidden" name="action" value="create_promo">
          <label>Код (4–16, латиница и цифры)</label>
          <input name="code" required minlength="4" maxlength="16" pattern="[A-Za-z0-9]+" placeholder="MYBRAND">
          <button class="btn-p" type="submit">Создать навсегда</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
