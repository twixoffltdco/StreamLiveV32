/**
 * Анти-хиты InfinityFree: режем poll fetch в фоне / idle, пауза вкладки.
 * v=hits2
 */
(function () {
  'use strict';
  var IDLE_SOFT = 3 * 60 * 1000;   // 3 мин без действий — только критичное
  var IDLE_HARD = 20 * 60 * 1000;  // 20 мин — полная пауза (вкладка открыта)
  var lastAct = Date.now();
  var hardOff = false;

  function bump() { lastAct = Date.now(); }
  ['pointerdown', 'keydown', 'scroll', 'touchstart', 'mousemove'].forEach(function (ev) {
    document.addEventListener(ev, bump, { passive: true, capture: true });
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') bump();
  });

  window.__slHitsAllowed = function () {
    if (hardOff || window.__slIdleOffline) return false;
    if (document.visibilityState !== 'visible') return false;
    if (Date.now() - lastAct > IDLE_SOFT) return false;
    return true;
  };

  var _fetch = window.fetch;
  if (typeof _fetch === 'function') {
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var isPoll = /_poll|notifications_poll|forum_poll|message_poll|chat_poll|comments_poll|watch_room|broadcast_post|schedule/i.test(url);
      if (isPoll && !window.__slHitsAllowed()) {
        return Promise.resolve(new Response('{"ok":true,"items":[],"messages":[],"paused":true}', {
          status: 200,
          headers: { 'Content-Type': 'application/json' }
        }));
      }
      return _fetch.apply(this, arguments);
    };
  }

  // Патч setInterval: растягиваем короткие poll-таймеры
  var _si = window.setInterval;
  window.setInterval = function (fn, ms) {
    if (typeof ms === 'number' && ms > 0 && ms < 30000) {
      // не трогаем UI анимации < 500ms
      if (ms >= 1000) ms = Math.max(ms * 3, 45000);
    }
    return _si.call(this, function () {
      if (document.visibilityState !== 'visible') return;
      if (window.__slIdleOffline) return;
      try { fn(); } catch (e) {}
    }, ms);
  };

  setInterval(function () {
    if (hardOff) return;
    if (Date.now() - lastAct < IDLE_HARD) return;
    if (document.visibilityState !== 'visible') return;
    hardOff = true;
    window.__slIdleOffline = true;
    var el = document.createElement('div');
    el.id = 'slIdleHard';
    el.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(8,8,12,.92);display:flex;align-items:center;justify-content:center;padding:20px;color:#fff;text-align:center;font-family:system-ui,sans-serif';
    el.innerHTML = '<div style="max-width:380px"><div style="font-size:28px;margin-bottom:10px">💤</div><div style="font-size:18px;font-weight:600;margin-bottom:8px">Пауза (хиты)</div><p style="opacity:.75;font-size:14px;line-height:1.45;margin:0 0 16px">Вкладка открыта, фоновые запросы остановлены. Нажми «Продолжить».</p><button type="button" id="slIdleGo" style="padding:12px 22px;border:0;border-radius:22px;background:#3ea6ff;color:#0b0b10;font-weight:700;cursor:pointer;font-size:14px">Продолжить</button></div>';
    document.body.appendChild(el);
    document.getElementById('slIdleGo').onclick = function () {
      hardOff = false;
      window.__slIdleOffline = false;
      lastAct = Date.now();
      el.remove();
    };
  }, 60000);
})();
