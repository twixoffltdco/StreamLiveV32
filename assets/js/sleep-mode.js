/**
 * Спящий режим вкладки: без взаимодействий сайт «засыпает».
 * Таймаут 10–30 мин в зависимости от нагрузки ЭТОЙ вкладки (fetch/poll).
 * Вкладка не закрывается — только пауза запросов + оверлей.
 */
(function () {
  'use strict';
  if (window.__slSleepModeInit) return;
  window.__slSleepModeInit = true;

  var MS = {
    min: 10 * 60 * 1000,  // при высокой нагрузке вкладки
    mid: 20 * 60 * 1000,  // обычно
    max: 30 * 60 * 1000   // спокойная вкладка
  };

  var lastAct = Date.now();
  var fetchCount = 0;
  var sleepOn = false;
  var checkTimer = null;

  function bump() {
    if (sleepOn) return;
    lastAct = Date.now();
  }

  ['pointerdown', 'keydown', 'scroll', 'touchstart', 'mousemove', 'click', 'wheel'].forEach(function (ev) {
    document.addEventListener(ev, bump, { passive: true, capture: true });
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') bump();
  });

  /** Чем больше fetch с вкладки — тем раньше сон (нагрузка пользователя) */
  function idleLimit() {
    if (fetchCount >= 40) return MS.min;      // ~10 мин
    if (fetchCount >= 15) return MS.mid;      // ~20 мин
    return MS.max;                            // ~30 мин
  }

  window.__slHitsAllowed = window.__slHitsAllowed || function () {
    return !sleepOn && document.visibilityState === 'visible';
  };
  var prevAllowed = window.__slHitsAllowed;
  window.__slHitsAllowed = function () {
    if (sleepOn) return false;
    if (document.visibilityState !== 'visible') return false;
    try {
      return prevAllowed ? !!prevAllowed() : true;
    } catch (e) {
      return true;
    }
  };
  window.__slIdleOffline = false;

  // Считаем fetch этой вкладки
  if (typeof window.fetch === 'function') {
    var _fetch = window.fetch;
    window.fetch = function (input, init) {
      fetchCount++;
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var isPoll = /_poll|notifications|chat_poll|comments_poll|vibe_watching|message_poll|forum_poll|watch_room/i.test(url);
      if (sleepOn || (isPoll && !window.__slHitsAllowed())) {
        return Promise.resolve(new Response('{"ok":true,"paused":true,"items":[],"messages":[]}', {
          status: 200,
          headers: { 'Content-Type': 'application/json' }
        }));
      }
      return _fetch.apply(this, arguments);
    };
  }

  function showSleep() {
    if (sleepOn) return;
    sleepOn = true;
    window.__slIdleOffline = true;

    var mins = Math.round(idleLimit() / 60000);
    var el = document.createElement('div');
    el.id = 'slSleepOverlay';
    el.setAttribute('role', 'dialog');
    el.innerHTML =
      '<div class="sl-sleep-card">' +
        '<div class="sl-sleep-ico">💤</div>' +
        '<div class="sl-sleep-title">Сайт в спящем режиме</div>' +
        '<p class="sl-sleep-text">Нет действий ~' + mins + ' мин. Запросы остановлены, чтобы не грузить хостинг. Вкладка открыта.</p>' +
        '<button type="button" id="slSleepWake" class="sl-sleep-btn">Продолжить</button>' +
      '</div>';
    document.body.appendChild(el);
    document.getElementById('slSleepWake').onclick = wake;
  }

  function wake() {
    sleepOn = false;
    window.__slIdleOffline = false;
    lastAct = Date.now();
    fetchCount = Math.floor(fetchCount * 0.3); // сброс части счётчика
    var el = document.getElementById('slSleepOverlay');
    if (el) el.remove();
  }

  function tick() {
    if (sleepOn) return;
    if (document.visibilityState !== 'visible') return;
    if (Date.now() - lastAct >= idleLimit()) showSleep();
  }

  checkTimer = setInterval(tick, 30000);

  // Стили один раз
  if (!document.getElementById('slSleepCss')) {
    var s = document.createElement('style');
    s.id = 'slSleepCss';
    s.textContent =
      '#slSleepOverlay{position:fixed;inset:0;z-index:999999;background:rgba(8,8,12,.92);display:flex;align-items:center;justify-content:center;padding:20px;font-family:system-ui,sans-serif}' +
      '.sl-sleep-card{max-width:380px;text-align:center;color:#fff}' +
      '.sl-sleep-ico{font-size:36px;margin-bottom:10px}' +
      '.sl-sleep-title{font-size:20px;font-weight:700;margin-bottom:8px}' +
      '.sl-sleep-text{opacity:.75;font-size:14px;line-height:1.45;margin:0 0 18px}' +
      '.sl-sleep-btn{padding:12px 24px;border:0;border-radius:22px;background:#3ea6ff;color:#0b0b10;font-weight:700;cursor:pointer;font-size:14px}' +
      'html.light-mode #slSleepOverlay{background:rgba(255,255,255,.94)}' +
      'html.light-mode .sl-sleep-card{color:#111}' +
      'html.light-mode .sl-sleep-btn{background:#111;color:#fff}';
    document.head.appendChild(s);
  }

  window.slSleepWake = wake;
})();
