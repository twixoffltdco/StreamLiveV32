/**
 * Спящий режим. Без подписки: 1 мин. С подпиской/промо: warn 4 мин, сон 5 мин.
 * Важно: mousemove НЕ сбрасывает таймер (иначе сон никогда не наступает).
 */
(function () {
  if (window.__SL_IDLE_INIT) return;
  window.__SL_IDLE_INIT = true;

  var cfg = window.__SL_IDLE_CFG || {};
  var hasSub = !!cfg.hasSub;
  var IDLE_MS = hasSub ? 5 * 60 * 1000 : 60 * 1000;
  var WARN_MS = hasSub ? 4 * 60 * 1000 : 40 * 1000;

  var last = Date.now();
  var sleeping = false;
  var warned = false;
  var tickId = 0;
  var overlay = null;
  var warnBar = null;

  function mediaPlaying() {
    try {
      var nodes = document.querySelectorAll('video, audio');
      for (var i = 0; i < nodes.length; i++) {
        var el = nodes[i];
        if (el && !el.paused && !el.ended) return true;
      }
    } catch (e) {}
    return false;
  }

  function ensureWarnBar() {
    if (warnBar && document.body.contains(warnBar)) return warnBar;
    warnBar = document.createElement('div');
    warnBar.id = 'sl-idle-warn';
    warnBar.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483646;background:#1e1b4b;color:#e0e7ff;border:2px solid #a78bfa;border-radius:14px;padding:14px 16px;font:700 14px/1.45 system-ui,sans-serif;box-shadow:0 12px 40px rgba(0,0,0,.55);display:none;text-align:center';
    warnBar.textContent = 'Через минуту — спящий режим (экономия хостинга). Кликните или нажмите клавишу, чтобы остаться онлайн.';
    (document.body || document.documentElement).appendChild(warnBar);
    return warnBar;
  }

  function ensureOverlay() {
    if (overlay && document.body.contains(overlay)) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'sl-idle-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(5,8,16,.88);display:none;align-items:center;justify-content:center;padding:20px';
    overlay.innerHTML = '<div style="max-width:420px;width:100%;background:#0f172a;border:2px solid #38bdf8;border-radius:18px;padding:28px 22px;text-align:center;color:#f8fafc;font-family:system-ui,sans-serif">'
      + '<div style="font-size:40px;line-height:1;margin-bottom:10px">😴</div>'
      + '<div style="font-weight:900;font-size:22px;margin-bottom:10px">Платформа в спящем режиме</div>'
      + '<p style="margin:0 0 18px;font-size:14px;line-height:1.55;color:#94a3b8">Фоновые запросы остановлены, чтобы не жечь хиты на хостинге. Вкладка открыта. Нажмите кнопку или клавишу, чтобы продолжить.</p>'
      + '<button type="button" id="sl-idle-wake" style="border:0;border-radius:12px;padding:12px 20px;font-weight:900;font-size:15px;cursor:pointer;background:#00a2ff;color:#fff">Продолжить</button>'
      + '</div>';
    (document.body || document.documentElement).appendChild(overlay);
    var btn = document.getElementById('sl-idle-wake');
    if (btn) btn.onclick = function (e) { e.preventDefault(); e.stopPropagation(); wake(true); };
    overlay.onclick = function (e) { if (e.target === overlay) wake(true); };
    return overlay;
  }

  function stopBackgroundTimers() {
    try {
      var maxId = setTimeout(function () {}, 0);
      for (var i = 0; i <= maxId; i++) {
        if (i === tickId) continue;
        clearTimeout(i);
        clearInterval(i);
      }
    } catch (e) {}
  }

  function sleep() {
    if (sleeping) return;
    sleeping = true;
    window.__SL_SLEEPING = true;
    document.documentElement.setAttribute('data-sl-sleep', '1');
    if (warnBar) warnBar.style.display = 'none';
    var ov = ensureOverlay();
    ov.style.display = 'flex';
    if (!mediaPlaying()) stopBackgroundTimers();
  }

  function wake(doReload) {
    last = Date.now();
    warned = false;
    if (warnBar) warnBar.style.display = 'none';
    if (overlay) overlay.style.display = 'none';
    if (!sleeping) return;
    sleeping = false;
    window.__SL_SLEEPING = false;
    document.documentElement.removeAttribute('data-sl-sleep');
    if (doReload && !mediaPlaying()) {
      try { location.reload(); } catch (e) {}
    }
  }

  // Только явные действия — НЕ mousemove
  function touch() {
    if (sleeping) { wake(true); return; }
    last = Date.now();
    if (warned) {
      warned = false;
      if (warnBar) warnBar.style.display = 'none';
    }
  }
  ['mousedown', 'keydown', 'scroll', 'touchstart', 'click', 'pointerdown'].forEach(function (ev) {
    document.addEventListener(ev, touch, true);
  });

  function tick() {
    if (sleeping) return;
    if (!document.body) return;
    var idle = Date.now() - last;
    if (idle >= IDLE_MS) {
      sleep();
      return;
    }
    if (!warned && idle >= WARN_MS) {
      warned = true;
      ensureWarnBar().style.display = 'block';
    }
  }

  function start() {
    last = Date.now();
    tickId = setInterval(tick, 2000);
    // авто-тест: можно ?sl_idle_test=1 → сон через 8 сек
    try {
      if (/[?&]sl_idle_test=1/.test(location.search)) {
        IDLE_MS = 8000;
        WARN_MS = 4000;
      }
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
