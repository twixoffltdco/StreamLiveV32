/**
 * Анти-хиты + спящий режим (InfinityFree).
 * Без подписки/промо: сон ~1 мин.
 * С подпиской/промо: warn ~4 мин, сон ~5 мин.
 * mousemove НЕ сбрасывает таймер.
 * v=hits3
 */
(function () {
  'use strict';
  if (window.__SL_HITS_GUARD) return;
  window.__SL_HITS_GUARD = true;

  var cfg = window.__SL_IDLE_CFG || {};
  var hasSub = !!cfg.hasSub;
  var IDLE_SOFT = hasSub ? 2 * 60 * 1000 : 30 * 1000;   // раньше режем poll
  var IDLE_HARD = hasSub ? 5 * 60 * 1000 : 60 * 1000;   // оверлей сна
  var WARN_AT = hasSub ? 4 * 60 * 1000 : 40 * 1000;

  try {
    if (/[?&]sl_idle_test=1/.test(location.search || '')) {
      IDLE_SOFT = 3000;
      WARN_AT = 4000;
      IDLE_HARD = 8000;
    }
  } catch (e) {}

  var lastAct = Date.now();
  var hardOff = false;
  var warned = false;
  var warnEl = null;
  var hardEl = null;

  function bump() {
    lastAct = Date.now();
    if (warned && warnEl) {
      warned = false;
      try { warnEl.style.display = 'none'; } catch (e) {}
    }
  }

  // БЕЗ mousemove
  ['pointerdown', 'keydown', 'scroll', 'touchstart', 'click', 'mousedown'].forEach(function (ev) {
    document.addEventListener(ev, bump, { passive: true, capture: true });
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') bump();
  });

  window.__slHitsAllowed = function () {
    if (hardOff || window.__slIdleOffline || window.__SL_SLEEPING) return false;
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

  var _si = window.setInterval;
  window.setInterval = function (fn, ms) {
    if (typeof ms === 'number' && ms >= 1000 && ms < 30000) {
      ms = Math.max(ms * 2, 30000);
    }
    return _si.call(this, function () {
      if (document.visibilityState !== 'visible') return;
      if (window.__slIdleOffline || window.__SL_SLEEPING) return;
      try { fn(); } catch (e) {}
    }, ms);
  };

  function ensureWarn() {
    if (warnEl) return warnEl;
    warnEl = document.createElement('div');
    warnEl.id = 'slIdleWarn';
    warnEl.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:2147483646;background:#312e81;color:#eef2ff;border:2px solid #a78bfa;border-radius:14px;padding:14px 16px;font:700 14px/1.45 system-ui,sans-serif;text-align:center;display:none;box-shadow:0 10px 40px rgba(0,0,0,.5)';
    warnEl.textContent = 'Скоро спящий режим. Кликните или нажмите клавишу, чтобы остаться онлайн.';
    (document.body || document.documentElement).appendChild(warnEl);
    return warnEl;
  }

  function showHard() {
    if (hardOff) return;
    hardOff = true;
    window.__slIdleOffline = true;
    window.__SL_SLEEPING = true;
    document.documentElement.setAttribute('data-sl-sleep', '1');
    if (warnEl) warnEl.style.display = 'none';

    hardEl = document.createElement('div');
    hardEl.id = 'slIdleHard';
    hardEl.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(3,7,18,.92);display:flex;align-items:center;justify-content:center;padding:20px;color:#fff;text-align:center;font-family:system-ui,sans-serif';
    hardEl.innerHTML = '<div style="max-width:420px;width:100%;background:#020617;border:3px solid #38bdf8;border-radius:18px;padding:26px 20px"><div style="font-size:40px;margin-bottom:8px">😴</div><div style="font-size:22px;font-weight:900;margin-bottom:10px">Платформа в спящем режиме</div><p style="opacity:.8;font-size:14px;line-height:1.5;margin:0 0 16px;color:#94a3b8">Вкладка открыта. Фоновые запросы остановлены — меньше хитов на хостинге. Нажмите «Продолжить».</p><button type="button" id="slIdleGo" style="padding:12px 22px;border:0;border-radius:12px;background:#0ea5e9;color:#fff;font-weight:900;cursor:pointer;font-size:15px">Продолжить</button></div>';
    (document.body || document.documentElement).appendChild(hardEl);
    var btn = document.getElementById('slIdleGo');
    if (btn) btn.onclick = function () {
      hardOff = false;
      window.__slIdleOffline = false;
      window.__SL_SLEEPING = false;
      document.documentElement.removeAttribute('data-sl-sleep');
      lastAct = Date.now();
      warned = false;
      if (hardEl) { hardEl.remove(); hardEl = null; }
    };
  }

  _si.call(window, function () {
    if (hardOff) return;
    if (document.visibilityState !== 'visible') return;
    if (!document.body) return;
    var idle = Date.now() - lastAct;
    if (idle >= IDLE_HARD) {
      showHard();
      return;
    }
    if (!warned && idle >= WARN_AT) {
      warned = true;
      ensureWarn().style.display = 'block';
    }
  }, 1500);

  try { document.documentElement.setAttribute('data-sl-hits', 'on'); } catch (e) {}
})();
