(function () {
  function setCookie(name, val, days) {
    var max = (days || 365) * 86400;
    var c = name + '=' + encodeURIComponent(val) + ';path=/;max-age=' + max + ';SameSite=Lax';
    if (location.protocol === 'https:') c += ';Secure';
    document.cookie = c;
  }

  function applyLight(on) {
    var root = document.documentElement;
    var body = document.body;
    root.classList.toggle('light-mode', on);
    root.setAttribute('data-pl-skin', on ? 'light' : 'dark');
    root.style.colorScheme = on ? 'light' : 'dark';
    if (body) {
      body.classList.toggle('light-mode', on);
      body.setAttribute('data-pl-skin', on ? 'light' : 'dark');
    }
    setCookie('site_color_mode', on ? 'light' : 'dark');
    setCookie('pl_skin', on ? 'light' : 'dark');
    // сброс конфликтующих пресетов
    document.cookie = 'pl_preset=;path=/;max-age=0;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
    var btn = document.getElementById('color-mode-toggle');
    if (btn) btn.textContent = on ? '🌙' : '☀️';
    if (typeof window.__plApplySkin === 'function') {
      try { window.__plApplySkin(); } catch (e) {}
    }
  }

  function isLight() {
    return document.documentElement.classList.contains('light-mode')
      || document.documentElement.getAttribute('data-pl-skin') === 'light';
  }

  document.addEventListener('DOMContentLoaded', function () {
    // синхронизация body
    if (isLight() && document.body) {
      document.body.classList.add('light-mode');
      document.body.setAttribute('data-pl-skin', 'light');
    }
    var btn = document.getElementById('color-mode-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      applyLight(!isLight());
    });
  });

  window.slApplyLightTheme = applyLight;
})();
