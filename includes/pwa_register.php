<script>
(function () {
  if (!('serviceWorker' in navigator)) return;

  var ua = navigator.userAgent || '';
  function isIos() {
    return /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function isYandex() { return /yabrowser|yandex/i.test(ua); }
  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;
  }

  function helpText() {
    if (isIos()) return 'iPhone: Поделиться → «На экран Домой»';
    if (isYandex()) return 'Яндекс ПК: меню ☰ (три линии) → «Установить приложение». Или иконка ⊕ в адресной строке справа.';
    return 'Chrome ПК: иконка установки ⊕ / компьютера в ПРАВОЙ части адресной строки. Или ⋮ → «Установить StreamLive…»';
  }

  var deferred = null;

  function ensureBar() {
    if (isStandalone() || document.getElementById('sl-pwa-install-bar')) return;
    try { if (localStorage.getItem('sl_pwa_hide') === '1') return; } catch (e) {}

    var wrap = document.createElement('div');
    wrap.id = 'sl-pwa-install-bar';
    wrap.style.cssText = 'position:fixed;z-index:10060;left:12px;right:12px;bottom:72px;max-width:460px;margin:0 auto;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:14px;background:rgba(28,28,30,.95);color:#f2f2f7;border:1px solid rgba(255,255,255,.12);box-shadow:0 8px 28px rgba(0,0,0,.4);font:600 13px/1.35 system-ui,sans-serif';

    var txt = document.createElement('div');
    txt.id = 'sl-pwa-install-txt';
    txt.style.flex = '1';
    txt.style.minWidth = '0';
    txt.textContent = 'Установить как приложение';

    var btn = document.createElement('button');
    btn.id = 'sl-pwa-install-btn';
    btn.type = 'button';
    btn.textContent = 'Установить';
    btn.style.cssText = 'border:0;border-radius:10px;padding:8px 12px;cursor:pointer;font-weight:700;background:#e50914;color:#fff;flex-shrink:0';

    var close = document.createElement('button');
    close.type = 'button';
    close.textContent = '×';
    close.style.cssText = 'border:0;background:transparent;color:#aaa;font-size:18px;cursor:pointer;padding:4px';
    close.onclick = function () {
      wrap.style.display = 'none';
      try { localStorage.setItem('sl_pwa_hide', '1'); } catch (e) {}
    };

    btn.onclick = function () {
      if (deferred) {
        deferred.prompt();
        deferred.userChoice.finally(function () {
          deferred = null;
          wrap.style.display = 'none';
        });
        return;
      }
      txt.textContent = helpText();
      btn.textContent = 'OK';
      btn.onclick = function () { wrap.style.display = 'none'; };
    };

    wrap.appendChild(txt);
    wrap.appendChild(btn);
    wrap.appendChild(close);
    (document.body || document.documentElement).appendChild(wrap);
  }

  // SW: после первого контроля — один reload (иначе beforeinstallprompt часто молчит)
  navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function (reg) {
    try { reg.update(); } catch (e) {}

    if (navigator.serviceWorker.controller) {
      // уже контролирует
    } else if (reg.installing || reg.waiting || reg.active) {
      try {
        if (!sessionStorage.getItem('sl_pwa_reloaded')) {
          var needReload = true;
          navigator.serviceWorker.addEventListener('controllerchange', function () {
            if (!needReload) return;
            needReload = false;
            try { sessionStorage.setItem('sl_pwa_reloaded', '1'); } catch (e) {}
            location.reload();
          });
          // если claim уже был — запасной reload через 2с
          setTimeout(function () {
            if (!navigator.serviceWorker.controller && needReload) {
              try { sessionStorage.setItem('sl_pwa_reloaded', '1'); } catch (e) {}
              location.reload();
            }
          }, 2500);
        }
      } catch (e) {}
    }
  }).catch(function () {
    setTimeout(function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
    }, 2000);
  });

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    ensureBar();
    var t = document.getElementById('sl-pwa-install-txt');
    if (t) t.textContent = 'Установить StreamLive как приложение';
    var b = document.getElementById('sl-pwa-install-btn');
    if (b) { b.textContent = 'Установить'; }
  });

  if (!isStandalone()) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', ensureBar);
    } else {
      ensureBar();
    }
  }
})();
</script>
