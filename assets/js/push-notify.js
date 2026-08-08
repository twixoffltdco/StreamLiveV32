/**
 * Без постоянного poll: проверка только при фокусе вкладки / раз в 30 мин максимум.
 * Service Worker показывает системное уведомление.
 */
(function () {
  if (!('Notification' in window)) return;
  var KEY = 'sl_notif_after';
  var LAST_CHECK = 'sl_notif_last_check';
  var MIN_GAP = 30 * 60 * 1000; // 30 мин
  var last = 0, busy = false;
  try { last = parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (e) {}

  function registerSW() {
    if (!('serviceWorker' in navigator)) return Promise.resolve(null);
    return navigator.serviceWorker.register('/sw-push.js').catch(function () { return null; });
  }

  function showLocal(items) {
    if (!items || !items.length) return;
    if (Notification.permission !== 'granted') return;
    registerSW().then(function (reg) {
      items.forEach(function (it) {
        var title = it.title || 'StreamLive';
        var body = it.message || it.body || '';
        var url = it.link || it.url || '/';
        if (reg && reg.showNotification) {
          reg.showNotification(title, { body: body, data: { url: url }, icon: '/assets/img/avatar-placeholder.png' });
        } else {
          try { new Notification(title, { body: body }); } catch (e) {}
        }
      });
    });
  }

  function pollOnce() {
    if (busy) return;
    if (document.visibilityState !== 'visible') return;
    if (window.__slHitsAllowed && !window.__slHitsAllowed()) return;
    var now = Date.now();
    try {
      var lc = parseInt(localStorage.getItem(LAST_CHECK) || '0', 10) || 0;
      if (now - lc < MIN_GAP) return;
    } catch (e) {}
    busy = true;
    try { localStorage.setItem(LAST_CHECK, String(now)); } catch (e) {}
    fetch('/api_notifications_poll.php?after=' + encodeURIComponent(last), { credentials: 'same-origin' })
      .then(function (r) { return r.status === 429 ? null : r.json(); })
      .then(function (d) {
        if (!d || !d.items || !d.items.length) return;
        var show = [];
        d.items.forEach(function (it) {
          var id = parseInt(it.id, 10) || 0;
          if (id > last) last = id;
          show.push(it);
        });
        try { localStorage.setItem(KEY, String(last)); } catch (e) {}
        showLocal(show);
      })
      .catch(function () {})
      .finally(function () { busy = false; });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') setTimeout(pollOnce, 1500);
  });
  window.addEventListener('focus', function () { setTimeout(pollOnce, 2000); });
  // один раз после загрузки, без цикла
  setTimeout(pollOnce, 8000);

  // кнопка разрешения
  var btn = document.getElementById('slEnablePush');
  if (btn) {
    btn.onclick = function () {
      Notification.requestPermission().then(function (p) {
        if (p === 'granted') { registerSW(); pollOnce(); }
      });
    };
  }
})();
