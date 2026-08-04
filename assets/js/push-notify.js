/**
 * StreamLive push: баннер разрешения + polling /api_notifications_poll
 * Когда permission granted и пользователь залогинен — браузерные Notification
 * при новом сообщении, видео, ответе на форуме и т.д.
 */
(function () {
  'use strict';
  if (!('Notification' in window)) return;

  var KEY = 'sl_watch_channels';
  var DISMISS_KEY = 'sl_push_banner_dismiss';
  var AFTER_KEY = 'sl_notif_after';
  var DISMISS_DAYS = 7;
  var pollTimer = null;
  var lastAfter = 0;

  try { lastAfter = parseInt(localStorage.getItem(AFTER_KEY) || '0', 10) || 0; } catch (e) {}

  function loadWatch() {
    try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
  }
  function saveWatch(arr) {
    try { localStorage.setItem(KEY, JSON.stringify(arr.slice(0, 40))); } catch (e) {}
  }
  function isDismissed() {
    try {
      var ts = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
      return ts && (Date.now() - ts) < DISMISS_DAYS * 864e5;
    } catch (e) { return false; }
  }
  function setDismissed() {
    try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) {}
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
        tag: 'sl-' + (url || title || Math.random())
      });
      n.onclick = function () {
        window.focus();
        if (url) location.href = url;
        n.close();
      };
    } catch (e) {}
  }

  function typeTitle(type) {
    var map = {
      message: 'Сообщение',
      video_new: 'Новое видео',
      channel_new: 'Новый канал',
      forum_reply: 'Форум',
      system: 'StreamLive'
    };
    return map[type] || 'Уведомление';
  }

  function poll() {
    if (Notification.permission !== 'granted') return;
    var url = '/api_notifications_poll?after=' + encodeURIComponent(lastAfter || 0);
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok || !d.items || !d.items.length) return;
        var items = d.items.slice().sort(function (a, b) { return a.id - b.id; });
        items.forEach(function (it) {
          var id = parseInt(it.id, 10) || 0;
          if (id > lastAfter) lastAfter = id;
          notify(typeTitle(it.type), it.message, it.link || '/');
        });
        try { localStorage.setItem(AFTER_KEY, String(lastAfter)); } catch (e) {}
        // пометить прочитанными после показа
        fetch('/api_notifications_poll', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'mark_all' })
        }).catch(function () {});
      })
      .catch(function () {});
  }

  function startPoll() {
    if (pollTimer) return;
    poll();
    pollTimer = setInterval(poll, 20000);
  }

  function removeBanner() {
    var el = document.getElementById('sl-push-banner');
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  function showBanner() {
    if (Notification.permission === 'granted') { startPoll(); return; }
    if (Notification.permission === 'denied') return;
    if (isDismissed()) return;
    if (document.getElementById('sl-push-banner')) return;

    var bar = document.createElement('div');
    bar.id = 'sl-push-banner';
    bar.innerHTML =
      '<div class="sl-push-inner">' +
        '<div class="sl-push-text"><b>Уведомления</b>' +
        '<span>Сообщения, новые видео, ответы на форуме — в браузере</span></div>' +
        '<div class="sl-push-btns">' +
          '<button type="button" class="sl-push-ok" id="sl-push-allow">Разрешить</button>' +
          '<button type="button" class="sl-push-no" id="sl-push-deny">Не сейчас</button>' +
        '</div></div>';
    var style = document.createElement('style');
    style.textContent =
      '#sl-push-banner{position:fixed;left:12px;right:12px;bottom:16px;z-index:100000;max-width:480px;margin:0 auto;' +
      'background:#1a1a22;color:#f1f1f1;border:1px solid #333;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.45);' +
      'padding:14px 16px;font-family:system-ui,sans-serif}' +
      '#sl-push-banner .sl-push-inner{display:flex;flex-direction:column;gap:12px}' +
      '#sl-push-banner .sl-push-text{display:flex;flex-direction:column;gap:4px;font-size:13px}' +
      '#sl-push-banner .sl-push-text span{color:#aaa}' +
      '#sl-push-banner .sl-push-btns{display:flex;gap:8px}' +
      '#sl-push-banner button{border:0;border-radius:10px;padding:10px 16px;font-weight:600;cursor:pointer;font-size:13px}' +
      '#sl-push-banner .sl-push-ok{background:#3ea6ff;color:#0f0f0f}' +
      '#sl-push-banner .sl-push-no{background:#333;color:#eee}' +
      '@media(min-width:600px){#sl-push-banner{left:auto;right:20px;bottom:20px;width:420px}}';
    document.head.appendChild(style);
    document.body.appendChild(bar);
    document.getElementById('sl-push-allow').onclick = function () {
      askPermission().then(function (ok) {
        removeBanner();
        if (ok) { notify('Уведомления включены', 'Сообщения, видео, форум'); startPoll(); }
        else setDismissed();
      });
    };
    document.getElementById('sl-push-deny').onclick = function () { setDismissed(); removeBanner(); };
  }

  window.SLPush = {
    watchChannel: function (id, title) {
      id = String(id);
      var list = loadWatch().filter(function (x) { return x.id !== id; });
      list.unshift({ id: id, title: title || ('Канал #' + id), ts: Date.now() });
      saveWatch(list);
      // серверная подписка
      fetch('/channel_subscribe', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ channel_id: parseInt(id, 10) || 0, action: 'subscribe' })
      }).catch(function () {});
      askPermission().then(function (ok) {
        if (ok) { notify('Подписка', title || id); startPoll(); }
      });
    },
    unwatchChannel: function (id) {
      id = String(id);
      saveWatch(loadWatch().filter(function (x) { return x.id !== id; }));
      fetch('/channel_subscribe', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ channel_id: parseInt(id, 10) || 0, action: 'unsubscribe' })
      }).catch(function () {});
    },
    notify: notify,
    ask: askPermission,
    startPoll: startPoll
  };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-sl-watch]');
    if (!btn) return;
    e.preventDefault();
    window.SLPush.watchChannel(btn.getAttribute('data-sl-watch'), btn.getAttribute('data-sl-title') || '');
    btn.textContent = '✓ Следите';
  });

  function boot() {
    if (Notification.permission === 'granted') startPoll();
    else setTimeout(showBanner, 1000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();

if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw-push.js').catch(function () {});
  });
}
