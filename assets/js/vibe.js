(function () {
  'use strict';

  function ensureToastWrap() {
    var w = document.getElementById('vibe-toast-wrap');
    if (!w) {
      w = document.createElement('div');
      w.id = 'vibe-toast-wrap';
      w.className = 'vibe-toast-wrap';
      document.body.appendChild(w);
    }
    return w;
  }
  window.vibeToast = function (msg, ms) {
    var w = ensureToastWrap();
    var el = document.createElement('div');
    el.className = 'vibe-toast';
    el.textContent = msg;
    w.appendChild(el);
    setTimeout(function () { try { el.remove(); } catch (e) {} }, ms || 4000);
  };

  // ---- QR: всегда работает (копирование + Web Share + картинка) ----
  window.vibeShowQr = function (url, title) {
    url = url || location.href;
    title = title || 'Поделиться';
    var old = document.getElementById('vibe-qr-modal');
    if (old) old.remove();

    var modal = document.createElement('div');
    modal.id = 'vibe-qr-modal';
    modal.className = 'vibe-qr-modal';
    modal.innerHTML =
      '<div class="vibe-qr-box" role="dialog">' +
        '<div style="font-weight:700;margin-bottom:6px;font-size:16px"></div>' +
        '<div id="vibe-qr-target" style="min-height:180px;display:flex;align-items:center;justify-content:center"></div>' +
        '<p id="vibe-qr-url" style="font-size:12px;opacity:.75;word-break:break-all;margin:10px 0"></p>' +
        '<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">' +
          '<button type="button" class="btn btn-primary" id="vibe-qr-copy">Скопировать ссылку</button>' +
          '<button type="button" class="btn btn-outline" id="vibe-qr-share">Поделиться</button>' +
          '<button type="button" class="btn btn-outline" id="vibe-qr-close">Закрыть</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);
    modal.querySelector('.vibe-qr-box > div').textContent = title;
    document.getElementById('vibe-qr-url').textContent = url;

    // QR-картинка: несколько зеркал, без краша если блок
    var target = document.getElementById('vibe-qr-target');
    var img = document.createElement('img');
    img.alt = 'QR';
    img.width = 200;
    img.height = 200;
    img.style.borderRadius = '8px';
    img.style.background = '#fff';
    var sources = [
      'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url),
      'https://quickchart.io/qr?size=200&text=' + encodeURIComponent(url),
      'https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=' + encodeURIComponent(url)
    ];
    var si = 0;
    img.onerror = function () {
      si++;
      if (si < sources.length) img.src = sources[si];
      else {
        target.innerHTML = '<div style="font-size:13px;opacity:.8;padding:12px">QR-картинка недоступна — скопируй ссылку</div>';
      }
    };
    img.src = sources[0];
    target.appendChild(img);

    document.getElementById('vibe-qr-close').onclick = function () { modal.remove(); };
    modal.addEventListener('click', function (ev) { if (ev.target === modal) modal.remove(); });
    document.getElementById('vibe-qr-copy').onclick = function () {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () {
          vibeToast('Ссылка скопирована');
        }).catch(function () { fallbackCopy(url); });
      } else fallbackCopy(url);
    };
    document.getElementById('vibe-qr-share').onclick = function () {
      if (navigator.share) {
        navigator.share({ title: title, url: url }).catch(function () {});
      } else {
        document.getElementById('vibe-qr-copy').click();
      }
    };
  };

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); vibeToast('Ссылка скопирована'); } catch (e) { vibeToast(text); }
    ta.remove();
  }

  // Клики: capture, чтобы не перехватывали другие handlers
  document.addEventListener('click', function (e) {
    var b = e.target && e.target.closest && e.target.closest('[data-vibe-qr]');
    if (!b) return;
    e.preventDefault();
    e.stopPropagation();
    vibeShowQr(b.getAttribute('data-vibe-qr') || location.href, b.getAttribute('data-vibe-qr-title') || 'Поделиться');
  }, true);

  // Реакции
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest && e.target.closest('[data-vibe-react]');
    if (!btn) return;
    e.preventDefault();
    var postId = btn.getAttribute('data-post-id');
    var emoji = btn.getAttribute('data-vibe-react');
    if (!postId || !emoji) return;
    var fd = new FormData();
    fd.set('post_id', postId);
    fd.set('emoji', emoji);
    var csrf = document.querySelector('input[name="_csrf"], input[name="csrf"]');
    if (csrf) fd.set(csrf.name, csrf.value);
    fetch('/api/vibe_react.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) {
          if (d && d.error === 'login') vibeToast('Войдите, чтобы ставить реакции');
          return;
        }
        var bar = btn.closest('.vibe-react-bar');
        if (!bar) return;
        bar.querySelectorAll('[data-vibe-react]').forEach(function (b) {
          var em = b.getAttribute('data-vibe-react');
          var c = (d.counts && d.counts[em]) ? d.counts[em] : 0;
          b.textContent = em + (c ? ' ' + c : '');
          b.classList.toggle('mine', d.my === em);
        });
      }).catch(function () {});
  }, true);

  // Watching heartbeat
  var chEl = document.querySelector('[data-vibe-channel-id]');
  if (chEl) {
    var cid = chEl.getAttribute('data-vibe-channel-id');
    var sk = localStorage.getItem('vibe_sk');
    if (!sk) {
      sk = Math.random().toString(36).slice(2) + Date.now().toString(36);
      try { localStorage.setItem('vibe_sk', sk); } catch (e) {}
    }
    function ping() {
      if (document.visibilityState !== 'visible') return;
      if (window.__slHitsAllowed && !window.__slHitsAllowed()) return;
      fetch('/api/vibe_watching.php?channel_id=' + encodeURIComponent(cid) + '&sk=' + encodeURIComponent(sk), {
        credentials: 'same-origin', cache: 'no-store'
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.ok) return;
        var el = document.getElementById('vibe-watching-count');
        if (el) el.textContent = d.watching;
      }).catch(function () {});
    }
    ping();
    setInterval(ping, 90000);
  }
})();
