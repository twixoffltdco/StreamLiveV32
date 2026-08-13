/**
 * Режим неактивности: >1 час без действий → оверлей «офлайн»,
 * вкладка НЕ закрывается, поллы/таймеры можно возобновить кнопкой.
 */
(function () {
  'use strict';
  var IDLE_MS = 60 * 60 * 1000; // 1 час
  var last = Date.now();
  var offline = false;
  var timer = null;

  function touch() {
    last = Date.now();
    if (offline) return; // не снимаем офлайн автоматически — только кнопкой
  }

  ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(function (ev) {
    window.addEventListener(ev, touch, { passive: true, capture: true });
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') touch();
  });

  function showOffline() {
    if (offline) return;
    offline = true;
    window.__slIdleOffline = true;
    var el = document.getElementById('slIdleOffline');
    if (!el) {
      el = document.createElement('div');
      el.id = 'slIdleOffline';
      el.innerHTML =
        '<div class="sl-idle-box">' +
        '<div style="font-size:28px;margin-bottom:8px">💤</div>' +
        '<div style="font-size:18px;font-weight:600;margin-bottom:8px">Режим неактивности</div>' +
        '<p style="font-size:14px;opacity:.8;margin:0 0 16px;line-height:1.45">Больше часа не было действий. Страница на паузе — так меньше нагрузка на хостинг. Вкладка не закрыта.</p>' +
        '<button type="button" id="slIdleResume" style="padding:10px 20px;border-radius:20px;border:0;background:#3ea6ff;color:#0b0b10;font-weight:600;cursor:pointer">Продолжить</button>' +
        '</div>';
      el.style.cssText =
        'position:fixed;inset:0;z-index:99999;background:rgba(8,8,12,.88);display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(6px)';
      var box = el.querySelector('.sl-idle-box');
      if (box) box.style.cssText =
        'max-width:380px;text-align:center;color:#f1f1f1;background:#1a1a22;border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:28px 22px';
      document.body.appendChild(el);
      document.getElementById('slIdleResume').onclick = function () {
        offline = false;
        window.__slIdleOffline = false;
        last = Date.now();
        el.remove();
      };
    }
  }

  function tick() {
    if (!offline && Date.now() - last >= IDLE_MS) showOffline();
  }
  timer = setInterval(tick, 30000);
  // Пауза поллов когда offline — патчим fetch лёгко
  var _fetch = window.fetch;
  if (typeof _fetch === 'function') {
    window.fetch = function () {
      if (window.__slIdleOffline) {
        return Promise.reject(new Error('idle-offline'));
      }
      return _fetch.apply(this, arguments);
    };
  }
})();
