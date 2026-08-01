<?php
/**
 * Реклама в плеерах (видео / embed ТВ-радио / страница канала).
 * Модель как у russtube / нашютуб: pre-roll → «Пропустить» через N сек →
 * после скипа / конца ролика cooldown 60 сек (localStorage), повторно не лезет.
 *
 * Настройки в таблице settings (через /admin/ads.php или set_setting):
 *   player_ads_enabled   = 1|0
 *   player_ads_html      = HTML креатива (или пусто — картинка+ссылка)
 *   player_ads_image_url = URL баннера
 *   player_ads_click_url = куда ведёт клик
 *   player_ads_skip_after= секунд до кнопки «Пропустить» (по умолчанию 5)
 *   player_ads_cooldown  = секунд до следующего показа (по умолчанию 60)
 *   player_ads_video_url = опционально mp4 pre-roll
 */

function player_ads_enabled(): bool {
  if (!function_exists('get_setting')) return false;
  return (string)get_setting('player_ads_enabled', '0') === '1';
}

function player_ads_config(): array {
  $skip = (int)(function_exists('get_setting') ? get_setting('player_ads_skip_after', '5') : 5);
  $cool = (int)(function_exists('get_setting') ? get_setting('player_ads_cooldown', '60') : 60);
  if ($skip < 0) $skip = 0;
  if ($skip > 30) $skip = 30;
  if ($cool < 10) $cool = 10;
  if ($cool > 3600) $cool = 3600;
  return [
    'enabled'   => player_ads_enabled(),
    'html'      => function_exists('get_setting') ? (string)get_setting('player_ads_html', '') : '',
    'image'     => function_exists('get_setting') ? (string)get_setting('player_ads_image_url', '') : '',
    'click'     => function_exists('get_setting') ? (string)get_setting('player_ads_click_url', '') : '',
    'video'     => function_exists('get_setting') ? (string)get_setting('player_ads_video_url', '') : '',
    'skip'      => $skip,
    'cooldown'  => $cool,
  ];
}

/**
 * Вывести overlay + скрипт. Кладётся ВНУТРЬ контейнера плеера (position:relative).
 * $context — метка для аналитики/разных cooldown-ключей: video|embed|channel
 */
function player_ads_render(string $context = 'player'): void {
  $cfg = player_ads_config();
  if (!$cfg['enabled']) return;

  $hasCreative = $cfg['html'] !== '' || $cfg['image'] !== '' || $cfg['video'] !== '';
  if (!$hasCreative) return;

  $id = 'plAd_' . preg_replace('/[^a-z0-9_]/i', '', $context) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
  $skip = (int)$cfg['skip'];
  $cool = (int)$cfg['cooldown'];
  $click = $cfg['click'];
  $img = $cfg['image'];
  $vid = $cfg['video'];
  $html = $cfg['html'];
  ?>
<div id="<?= htmlspecialchars($id, ENT_QUOTES) ?>" class="sl-player-ad" style="display:none;position:absolute;inset:0;z-index:40;background:#0a0a0a;color:#fff;font-family:system-ui,sans-serif">
  <div class="sl-player-ad-inner" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;text-align:center">
    <?php if ($vid !== ''): ?>
      <video class="sl-player-ad-video" src="<?= htmlspecialchars($vid, ENT_QUOTES) ?>" autoplay muted playsinline
        style="max-width:100%;max-height:70%;background:#000"></video>
    <?php elseif ($html !== ''): ?>
      <div class="sl-player-ad-html" style="max-width:100%;max-height:80%;overflow:auto"><?= $html /* HTML из админки — доверяем только админу */ ?></div>
    <?php elseif ($img !== ''): ?>
      <?php if ($click !== ''): ?><a href="<?= htmlspecialchars($click, ENT_QUOTES) ?>" target="_blank" rel="noopener sponsored"><?php endif; ?>
        <img src="<?= htmlspecialchars($img, ENT_QUOTES) ?>" alt="Реклама" style="max-width:100%;max-height:70%;object-fit:contain;border-radius:8px">
      <?php if ($click !== ''): ?></a><?php endif; ?>
    <?php endif; ?>
    <div style="margin-top:14px;font-size:12px;opacity:.7">Реклама · партнёрский блок</div>
    <button type="button" class="sl-player-ad-skip" disabled
      style="margin-top:12px;padding:10px 18px;border-radius:20px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.12);color:#fff;font-weight:600;cursor:not-allowed;opacity:.55">
      Пропустить<?= $skip > 0 ? ' через ' . $skip : '' ?>
    </button>
  </div>
</div>
<script>
(function(){
  var root = document.getElementById(<?= json_encode($id) ?>);
  if (!root) return;
  var skipBtn = root.querySelector('.sl-player-ad-skip');
  var adVideo = root.querySelector('.sl-player-ad-video');
  var skipAfter = <?= (int)$skip ?>;
  var cooldownMs = <?= (int)$cool ?> * 1000;
  var storageKey = 'sl_ad_cd_<?= htmlspecialchars($context, ENT_QUOTES) ?>';
  var clickUrl = <?= json_encode($click) ?>;

  function canShow() {
    try {
      var until = parseInt(localStorage.getItem(storageKey) || '0', 10);
      return !(until && Date.now() < until);
    } catch (e) { return true; }
  }
  function markCooldown() {
    try { localStorage.setItem(storageKey, String(Date.now() + cooldownMs)); } catch (e) {}
  }
  function hideAd() {
    root.style.display = 'none';
    if (adVideo) { try { adVideo.pause(); } catch (e) {} }
    // разморозить лежащий под ним media
    try {
      var parent = root.parentElement;
      if (parent) {
        parent.querySelectorAll('video').forEach(function(v){
          if (v.classList.contains('sl-player-ad-video')) return;
          var p = v.play(); if (p && p.catch) p.catch(function(){});
        });
      }
    } catch (e) {}
  }
  function enableSkip() {
    if (!skipBtn) return;
    skipBtn.disabled = false;
    skipBtn.style.cursor = 'pointer';
    skipBtn.style.opacity = '1';
    skipBtn.textContent = 'Пропустить';
  }
  function showAd() {
    if (!canShow()) return;
    root.style.display = 'block';
    // пауза основного видео если есть
    try {
      var parent = root.parentElement;
      if (parent) parent.querySelectorAll('video').forEach(function(v){
        if (v.classList.contains('sl-player-ad-video')) return;
        try { v.pause(); } catch (e) {}
      });
    } catch (e) {}
    if (adVideo) {
      try { adVideo.currentTime = 0; adVideo.play().catch(function(){}); } catch (e) {}
      adVideo.addEventListener('ended', function(){ markCooldown(); hideAd(); });
    }
    var left = skipAfter;
    if (left <= 0) {
      enableSkip();
    } else {
      skipBtn.textContent = 'Пропустить через ' + left;
      var t = setInterval(function(){
        left--;
        if (left <= 0) { clearInterval(t); enableSkip(); }
        else skipBtn.textContent = 'Пропустить через ' + left;
      }, 1000);
    }
  }
  if (skipBtn) {
    skipBtn.addEventListener('click', function(){
      if (skipBtn.disabled) return;
      markCooldown();
      hideAd();
      if (clickUrl) { /* скип не обязан открывать ссылку */ }
    });
  }
  // старт после готовности DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(showAd, 200); });
  } else {
    setTimeout(showAd, 200);
  }
})();
</script>
  <?php
}
