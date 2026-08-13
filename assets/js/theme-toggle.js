(function () {
  function setCookie(name, val, days) {
    var max = (days || 365) * 86400;
    var c = name + '=' + encodeURIComponent(val) + ';path=/;max-age=' + max + ';SameSite=Lax';
    if (location.protocol === 'https:') c += ';Secure';
    document.cookie = c;
  }

  function applyLight(on) {
    on = !!on;
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
    document.cookie = 'pl_preset=;path=/;max-age=0;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');

    var btn = document.getElementById('color-mode-toggle');
    if (btn) {
      btn.textContent = on ? '🌙' : '☀️';
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    try {
      void root.offsetHeight;
      if (body) void body.offsetHeight;
    } catch (e) {}
    if (typeof window.__plApplySkin === 'function') {
      try { window.__plApplySkin(); } catch (e) {}
    }
    // live-theme helper
    if (typeof window.__slLiveThemeApply === 'function') {
      try { window.__slLiveThemeApply(on ? 'light' : 'dark'); } catch (e) {}
    }
    try {
      window.dispatchEvent(new CustomEvent('sl-theme-change', { detail: { light: on } }));
    } catch (e) {}
  }

  function isLight() {
    return document.documentElement.classList.contains('light-mode')
      || document.documentElement.getAttribute('data-pl-skin') === 'light'
      || (document.body && document.body.getAttribute('data-pl-skin') === 'light');
  }

  function bind() {
    if (isLight() && document.body) {
      document.body.classList.add('light-mode');
      document.body.setAttribute('data-pl-skin', 'light');
      document.documentElement.classList.add('light-mode');
      document.documentElement.setAttribute('data-pl-skin', 'light');
    }
    var btn = document.getElementById('color-mode-toggle');
    if (!btn || btn.__slThemeBound) return;
    btn.__slThemeBound = true;
    function toggle(e) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      applyLight(!isLight());
    }
    btn.addEventListener('click', toggle, true);
    btn.addEventListener('touchend', toggle, { passive: false });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
  window.slApplyLightTheme = applyLight;
})();
