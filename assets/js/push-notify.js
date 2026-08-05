(function () {
  'use strict';
  if (!('Notification' in window)) return;
  var DISMISS_KEY = 'sl_push_banner_dismiss';
  var AFTER_KEY = 'sl_notif_after';
  var DISMISS_DAYS = 7;
  var POLL_MS = 90000;
  var pollTimer = null;
  var lastAfter = 0;
  try { lastAfter = parseInt(localStorage.getItem(AFTER_KEY) || '0', 10) || 0; } catch (e) {}
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
    return ({ message: 'Сообщение', video_new: 'Новое видео', forum_reply: 'Форум',
      schedule_soon: 'Скоро эфир', schedule_start: 'Эфир', system: 'StreamLive' })[type] || 'Уведомление';
  }
  function poll() {
    if (Notification.permission !== 'granted') return;
    if (document.visibilityState === 'hidden') return;
    fetch('/api_notifications_poll?after=' + encodeURIComponent(lastAfter || 0), {
      credentials: 'same-origin', headers: { 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.ok || !d.items || !d.items.length) return;
      d.items.slice().sort(function (a, b) { return a.id - b.id; }).forEach(function (it) {
        var id = parseInt(it.id, 10) || 0;
        if (id > lastAfter) lastAfter = id;
        notify(typeTitle(it.type), it.message, it.link || '/');
      });
      try { localStorage.setItem(AFTER_KEY, String(lastAfter)); } catch (e) {}
      fetch('/api_notifications_poll', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_all' })
      }).catch(function () {});
    }).catch(function () {});
  }
  function startPoll() {
    if (pollTimer) return;
    poll();
    pollTimer = setInterval(poll, POLL_MS);
  }
  function stopPoll() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') stopPoll();
    else if (Notification.permission === 'granted') startPoll();
  });
  function showBanner() {
    if (Notification.permission === 'granted') { startPoll(); return; }
    if (Notification.permission === 'denied' || isDismissed()) return;
    if (document.getElementById('sl-push-banner')) return;
    var bar = document.createElement('div');
    bar.id = 'sl-push-banner';
    bar.innerHTML = '<div class="sl-push-inner"><div class="sl-push-text"><b>Уведомления</b><span>Редкий опрос — экономия hits</span></div><div class="sl-push-btns"><button type="button" class="sl-push-ok" id="sl-push-allow">Разрешить</button><button type="button" class="sl-push-no" id="sl-push-deny">Не сейчас</button></div></div>';
    var style = document.createElement('style');
    style.textContent = '#sl-push-banner{position:fixed;left:12px;right:12px;bottom:16px;z-index:100000;max-width:480px;margin:0 auto;background:#1a1a22;color:#f1f1f1;border:1px solid #333;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.45);padding:14px 16px;font-family:system-ui,sans-serif}#sl-push-banner .sl-push-text{display:flex;flex-direction:column;gap:4px;font-size:13px;margin-bottom:10px}#sl-push-banner .sl-push-text span{color:#aaa}#sl-push-banner .sl-push-btns{display:flex;gap:8px}#sl-push-banner button{border:0;border-radius:10px;padding:10px 16px;font-weight:600;cursor:pointer;font-size:13px}#sl-push-banner .sl-push-ok{background:#3ea6ff;color:#0f0f0f}#sl-push-banner .sl-push-no{background:#333;color:#eee}@media(min-width:600px){#sl-push-banner{left:auto;right:20px;bottom:20px;width:420px}}';
    document.head.appendChild(style);
    document.body.appendChild(bar);
    document.getElementById('sl-push-allow').onclick = function () {
      askPermission().then(function (ok) {
        bar.remove();
        if (ok) { notify('Уведомления включены', 'Опрос раз в 1.5 мин'); startPoll(); }
        else setDismissed();
      });
    };
    document.getElementById('sl-push-deny').onclick = function () { setDismissed(); bar.remove(); };
  }
  window.SLPush = { notify: notify, ask: askPermission, startPoll: startPoll };
  function boot() {
    if (Notification.permission === 'granted') startPoll();
    else setTimeout(showBanner, 2500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
