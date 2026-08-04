/**
 * Live light/dark — без reload, поверх любых тем (FlexDev/Platform/TG).
 * Cookie: site_color_mode, pl_skin
 */
(function () {
  'use strict';

  var STYLE_ID = 'sl-live-theme-css';
  var LINK_ID = 'sl-light-force';

  function cookieGet(n) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + n.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }
  function cookieSet(n, v) {
    document.cookie = n + '=' + encodeURIComponent(v) + '; path=/; max-age=31536000; SameSite=Lax';
  }

  function isLight() {
    var s = cookieGet('pl_skin') || cookieGet('site_color_mode') || 'dark';
    return s === 'light';
  }

  var LIGHT_CSS = [
    'html.light-mode,html[data-pl-skin="light"],body[data-pl-skin="light"]{',
    '--bg:#fff!important;--bg-elevated:#f2f2f2!important;--card:#fff!important;',
    '--border:#e5e5e5!important;--text:#0f0f0f!important;--text-dim:#606060!important;',
    '--pl-bg:#fff!important;--pl-elev:#f2f2f2!important;--pl-hover:#e5e5e5!important;',
    '--pl-text:#0f0f0f!important;--pl-muted:#606060!important;--pl-border:#e5e5e5!important;',
    '--pl-chip:#f2f2f2!important;--pl-chip-on-bg:#0f0f0f!important;--pl-chip-on-fg:#fff!important;',
    '--pl-link:#065fd4!important;',
    '--tg-bg:#fff!important;--tg-elev:#f4f4f5!important;--tg-card:#fff!important;',
    '--tg-text:#0f0f0f!important;--tg-muted:#707579!important;--tg-hover:#e8e8ea!important;',
    '--tg-border:#e4e4e4!important;',
    'color-scheme:light!important;background:#fff!important;color:#0f0f0f!important;}',
    'html.light-mode body,html[data-pl-skin="light"] body,body[data-pl-skin="light"]{',
    'background:#fff!important;color:#0f0f0f!important;}',
    'html.light-mode .pl-yt-sidebar,html.light-mode #pl-yt-sidebar,',
    'html[data-pl-skin="light"] .pl-yt-sidebar,html[data-pl-skin="light"] #pl-yt-sidebar,',
    'body[data-pl-skin="light"] .pl-yt-sidebar,body[data-pl-skin="light"] #pl-yt-sidebar{',
    'background:#fff!important;background-color:#fff!important;color:#0f0f0f!important;',
    'border-right:1px solid #e5e5e5!important;}',
    'html.light-mode .pl-yt-nav-item,html[data-pl-skin="light"] .pl-yt-nav-item,',
    'body[data-pl-skin="light"] .pl-yt-nav-item{color:#0f0f0f!important;background:transparent!important;}',
    'html.light-mode .pl-yt-nav-item:hover,html.light-mode .pl-yt-nav-item.active,',
    'html[data-pl-skin="light"] .pl-yt-nav-item.active{background:#f2f2f2!important;color:#0f0f0f!important;}',
    'html.light-mode .pl-yt-nav-item svg,html[data-pl-skin="light"] .pl-yt-nav-item svg{fill:#0f0f0f!important;color:#0f0f0f!important;}',
    'html.light-mode .pl-yt-nav-label,html.light-mode .pl-yt-side-foot{color:#606060!important;}',
    'html.light-mode .pl-yt-nav-div{background:#e5e5e5!important;}',
    'html.light-mode .pl-yt-login-pill{border-color:#065fd4!important;color:#065fd4!important;background:transparent!important;}',
    'html.light-mode .pl-yt-bottom,html[data-pl-skin="light"] .pl-yt-bottom{background:#fff!important;border-top:1px solid #e5e5e5!important;}',
    'html.light-mode header,html.light-mode .header,html.light-mode .navbar,html.light-mode .site-header,',
    'html.light-mode footer,html.light-mode .footer,',
    'html[data-pl-skin="light"] header,html[data-pl-skin="light"] .navbar{',
    'background:#fff!important;color:#0f0f0f!important;border-color:#e5e5e5!important;}',
    'html.light-mode .card,html.light-mode .panel,html.light-mode .forum-post,html.light-mode .comment,',
    'html.light-mode .yt-desc,html[data-pl-skin="light"] .card,html[data-pl-skin="light"] .forum-post{',
    'background:#f8f8f8!important;color:#0f0f0f!important;border-color:#e5e5e5!important;box-shadow:none!important;}',
    'html.light-mode h1,html.light-mode h2,html.light-mode h3,html.light-mode .yt-title,html.light-mode .yt-card-title{color:#0f0f0f!important;}',
    'html.light-mode .yt-card-meta,html.light-mode .muted,html.light-mode small{color:#606060!important;}',
    'html.light-mode input,html.light-mode textarea,html.light-mode select{background:#fff!important;color:#0f0f0f!important;border:1px solid #ccc!important;}',
    'html.light-mode .btn-outline{background:#f2f2f2!important;color:#0f0f0f!important;border:none!important;}',
    'html.light-mode .btn-primary{background:#0f0f0f!important;color:#fff!important;}',
    'html.light-mode .tg-sidebar,html.light-mode .tg-bottom{background:#fff!important;color:#0f0f0f!important;}',
    'html.light-mode .tg-nav-item{color:#0f0f0f!important;}',
    'html.light-mode main,html.light-mode .container,html.light-mode .content{color:#0f0f0f!important;}'
  ].join('');

  function ensureStyle(on) {
    var el = document.getElementById(STYLE_ID);
    if (on) {
      if (!el) {
        el = document.createElement('style');
        el.id = STYLE_ID;
        el.textContent = LIGHT_CSS;
        document.head.appendChild(el);
      }
      var link = document.getElementById(LINK_ID);
      if (!link) {
        link = document.createElement('link');
        link.id = LINK_ID;
        link.rel = 'stylesheet';
        link.href = '/assets/css/light-force.css?v=20260803lf6';
        document.head.appendChild(link);
      }
    } else {
      if (el) el.remove();
      var link2 = document.getElementById(LINK_ID);
      if (link2) link2.remove();
    }
  }

  function paintSidebar(on) {
    var sb = document.getElementById('pl-yt-sidebar');
    if (!sb) return;
    if (on) {
      sb.style.setProperty('background', '#ffffff', 'important');
      sb.style.setProperty('background-color', '#ffffff', 'important');
      sb.style.setProperty('color', '#0f0f0f', 'important');
    } else {
      sb.style.removeProperty('background');
      sb.style.removeProperty('background-color');
      sb.style.removeProperty('color');
    }
  }

  function apply(forceSkin) {
    var light = forceSkin === 'light' || (forceSkin !== 'dark' && isLight());
    var root = document.documentElement;
    var body = document.body;

    if (light) {
      root.classList.add('light-mode');
      root.setAttribute('data-pl-skin', 'light');
      root.style.colorScheme = 'light';
      root.style.setProperty('--pl-bg', '#ffffff');
      root.style.setProperty('--bg', '#ffffff');
      root.style.setProperty('--text', '#0f0f0f');
      if (body) {
        body.setAttribute('data-pl-skin', 'light');
        body.style.setProperty('background', '#ffffff', 'important');
        body.style.setProperty('color', '#0f0f0f', 'important');
      }
    } else {
      root.classList.remove('light-mode');
      root.setAttribute('data-pl-skin', 'dark');
      root.style.colorScheme = 'dark';
      root.style.setProperty('--pl-bg', '#0f0f0f');
      root.style.setProperty('--bg', '#0b0b10');
      root.style.setProperty('--text', '#f2f2f7');
      if (body) {
        body.setAttribute('data-pl-skin', 'dark');
        body.style.removeProperty('background');
        body.style.removeProperty('color');
      }
    }
    ensureStyle(light);
    paintSidebar(light);
    if (typeof window.__plApplySkin === 'function') {
      try { window.__plApplySkin(); } catch (e) {}
    }
  }

  function setLight(on) {
    cookieSet('site_color_mode', on ? 'light' : 'dark');
    cookieSet('pl_skin', on ? 'light' : 'dark');
    cookieSet('pl_preset', '');
    apply(on ? 'light' : 'dark');
  }

  window.SLTheme = { apply: apply, setLight: setLight, isLight: isLight };

  // boot
  apply();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { apply(); });
  }
  setTimeout(apply, 100);
  setTimeout(apply, 500);

  // bind ☀️ button
  function bindBtn() {
    var btn = document.getElementById('color-mode-toggle');
    if (!btn || btn._slBound) return;
    btn._slBound = true;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      setLight(!isLight());
      btn.textContent = isLight() ? '🌙' : '☀️';
    }, true);
    btn.textContent = isLight() ? '🌙' : '☀️';
  }
  bindBtn();
  document.addEventListener('DOMContentLoaded', bindBtn);
  setTimeout(bindBtn, 300);
})();
