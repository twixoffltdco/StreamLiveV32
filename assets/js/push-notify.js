/**
 * Браузерные уведомления о каналах (без обязательной регистрации для разрешения).
 * Подписка «следить» — если есть user, иначе localStorage список.
 */
(function () {
  'use strict';
  if (!('Notification' in window)) return;

  var KEY = 'sl_watch_channels';

  function loadWatch() {
    try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
  }
  function saveWatch(arr) {
    try { localStorage.setItem(KEY, JSON.stringify(arr.slice(0, 40))); } catch (e) {}
  }

  function askPermission() {
    if (Notification.permission === 'granted') return Promise.resolve(true);
    if (Notification.permission === 'denied') return Promise.resolve(false);
    return Notification.requestPermission().then(function (p) { return p === 'granted'; });
  }

  function notify(title, body, url) {
    if (Notification.permission !== 'granted') return;
    try {
      var n = new Notification(title || 'StreamLive', {
        body: body || '',
        icon: '/assets/img/avatar-placeholder.png',
        tag: 'sl-' + (url || title || 'x')
      });
      n.onclick = function () {
        window.focus();
        if (url) location.href = url;
        n.close();
      };
    } catch (e) {}
  }

  window.SLPush = {
    watchChannel: function (id, title) {
      id = String(id);
      var list = loadWatch().filter(function (x) { return x.id !== id; });
      list.unshift({ id: id, title: title || ('Канал #' + id), ts: Date.now() });
      saveWatch(list);
      askPermission().then(function (ok) {
        if (ok) notify('Подписка на уведомления', 'Будем сообщать об обновлениях: ' + (title || id));
      });
    },
    unwatchChannel: function (id) {
      id = String(id);
      saveWatch(loadWatch().filter(function (x) { return x.id !== id; }));
    },
    notify: notify,
    ask: askPermission
  };

  // Кнопки [data-sl-watch]
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-sl-watch]');
    if (!btn) return;
    e.preventDefault();
    var id = btn.getAttribute('data-sl-watch');
    var title = btn.getAttribute('data-sl-title') || '';
    window.SLPush.watchChannel(id, title);
    btn.textContent = '✓ Следите';
  });
})();
