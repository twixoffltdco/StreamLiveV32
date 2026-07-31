/**
 * Platforma shell — YouTube 2026 + Studio chrome
 */
(function () {
  'use strict';

  function isOn() {
    return document.documentElement.classList.contains('pl-theme-platforma') ||
      (document.body && document.body.classList.contains('pl-theme-platforma'));
  }

  function ensureBodyClass() {
    document.documentElement.classList.add('pl-theme-platforma');
    if (document.body) document.body.classList.add('pl-theme-platforma');
  }

  var LINKS = [
    { href: '/', label: 'Главная', icon: 'home' },
    { href: '/channels.php', label: 'Каналы', icon: 'tv' },
    { href: '/videos.php', label: 'Видео', icon: 'play' },
    { href: '/shorts.php', label: 'Shorts', icon: 'shorts' },
    { href: '/resources.php', label: 'Ресурсы', icon: 'docs' },
    { href: '/forum.php', label: 'Форум', icon: 'chat' },
    { href: '/rating.php', label: 'Рейтинг', icon: 'star' },
    { href: '/messages.php', label: 'Мессенджер', icon: 'mail' },
    { href: '/platforma/studio/', label: 'Студия', icon: 'studio' },
    { href: '/platforma/studio/channel.php', label: 'Канал', icon: 'settings' },
    { href: '/platforma/studio/import.php', label: 'Импорт', icon: 'upload' }
  ];

  function iconSvg(name) {
    var paths = {
      home: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
      tv: 'M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 1.99-.9 1.99-2L23 5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z',
      play: 'M10 16.5l6-4.5-6-4.5v9zM12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z',
      shorts: 'M10 14.65l5.47-4.02c.4-.29.4-.92 0-1.21L10 5.35c-.5-.37-1.2-.01-1.2.61v8.08c0 .62.7.98 1.2.61zM17.5 12c0 3.03-2.47 5.5-5.5 5.5S6.5 15.03 6.5 12 8.97 6.5 12 6.5s5.5 2.47 5.5 5.5z',
      docs: 'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z',
      chat: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z',
      star: 'M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z',
      mail: 'M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
      menu: 'M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z',
      studio: 'M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z',
      settings: 'M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.48.48 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z',
      upload: 'M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z'
    };
    var d = paths[name] || paths.home;
    return '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="' + d + '"/></svg>';
  }

  function addAlphaBadge() {
    if (document.getElementById('pl-alpha-badge')) return;
    // ищем логотип / название
    var candidates = document.querySelectorAll(
      'header a, .header a, .navbar a, .logo, .site-logo, a.brand, .brand a'
    );
    var target = null;
    candidates.forEach(function (el) {
      if (target) return;
      var t = (el.textContent || '').trim();
      if (/streamlive|stream\s*life|платформ/i.test(t) || el.querySelector('img')) {
        target = el;
      }
    });
    if (!target) {
      // fallback: first prominent link in header
      var h = document.querySelector('header, .header, .navbar, .site-header');
      if (h) target = h.querySelector('a');
    }
    if (!target) return;
    var badge = document.createElement('span');
    badge.id = 'pl-alpha-badge';
    badge.className = 'pl-alpha-badge';
    badge.textContent = 'Альфа';
    badge.title = 'Режим оболочки Платформа (Альфа)';
    // после текста лого
    if (target.lastChild && target.lastChild.nodeType === 3) {
      target.appendChild(document.createTextNode(' '));
      target.appendChild(badge);
    } else {
      target.appendChild(badge);
    }
  }

  function buildChrome() {
    if (document.getElementById('pl-yt-sidebar')) return;

    var btn = document.createElement('button');
    btn.id = 'pl-yt-menu-btn';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Меню');
    btn.innerHTML = iconSvg('menu');
    btn.className = 'pl-yt-menu-btn';

    var sb = document.createElement('aside');
    sb.id = 'pl-yt-sidebar';
    sb.className = 'pl-yt-sidebar';
    var nav = document.createElement('nav');
    nav.className = 'pl-yt-nav';

    var mainLinks = LINKS.slice(0, 8);
    var studioLinks = LINKS.slice(8);

    mainLinks.forEach(function (l) {
      nav.appendChild(makeNavItem(l));
    });
    var div = document.createElement('div');
    div.className = 'pl-yt-nav-div';
    nav.appendChild(div);
    var lab = document.createElement('div');
    lab.className = 'pl-yt-nav-label';
    lab.textContent = 'Студия';
    nav.appendChild(lab);
    studioLinks.forEach(function (l) {
      nav.appendChild(makeNavItem(l));
    });

    sb.appendChild(nav);
    var foot = document.createElement('div');
    foot.className = 'pl-yt-side-foot';
    foot.innerHTML = '<p>Платформа · оболочка StreamLife</p>';
    sb.appendChild(foot);

    function makeNavItem(l) {
      var a = document.createElement('a');
      a.href = l.href;
      a.className = 'pl-yt-nav-item';
      var path = location.pathname || '';
      if (path === l.href || (l.href !== '/' && path.indexOf(l.href.replace(/^\//, '')) !== -1)) {
        a.classList.add('active');
      }
      a.innerHTML = iconSvg(l.icon) + '<span>' + l.label + '</span>';
      return a;
    }

    var back = document.createElement('div');
    back.id = 'pl-yt-backdrop';
    back.className = 'pl-yt-backdrop';

    var bottom = document.createElement('nav');
    bottom.id = 'pl-yt-bottom';
    bottom.className = 'pl-yt-bottom';
    [
      { href: '/', label: 'Главная', icon: 'home' },
      { href: '/shorts.php', label: 'Shorts', icon: 'shorts' },
      { href: '/channels.php', label: 'Каналы', icon: 'tv' },
      { href: '/platforma/studio/', label: 'Студия', icon: 'studio' },
      { href: '#menu', label: 'Ещё', icon: 'menu' }
    ].forEach(function (l) {
      var a = document.createElement('a');
      a.href = l.href;
      a.className = 'pl-yt-bottom-item';
      if (l.href === '#menu') {
        a.addEventListener('click', function (e) {
          e.preventDefault();
          toggle(true);
        });
      }
      a.innerHTML = iconSvg(l.icon) + '<span>' + l.label + '</span>';
      bottom.appendChild(a);
    });

    document.body.appendChild(back);
    document.body.appendChild(sb);
    document.body.appendChild(bottom);

    var header = document.querySelector('header, .header, .navbar, .site-header, #header, .main-header, .top-bar');
    if (header) {
      header.classList.add('pl-yt-header');
      if (header.firstChild) header.insertBefore(btn, header.firstChild);
      else header.appendChild(btn);
    } else {
      document.body.insertBefore(btn, document.body.firstChild);
    }

    function toggle(force) {
      var open = typeof force === 'boolean' ? force : !sb.classList.contains('open');
      sb.classList.toggle('open', open);
      back.classList.toggle('open', open);
      document.body.classList.toggle('pl-yt-drawer-open', open);
    }
    btn.addEventListener('click', function () { toggle(); });
    back.addEventListener('click', function () { toggle(false); });

    // Studio page mark
    var p = (location.pathname || '').toLowerCase();
    if (/dashboard|channel_manage|video_import|new_channel|broadcast/.test(p)) {
      document.body.classList.add('pl-yt-studio');
    }

    hideDecor();
    addAlphaBadge();
  }

  function hideDecor() {
    ['.leaf', '.petal', '.butterfly', '[class*="leaf"]', '[class*="petal"]', '[class*="sparkle"]', '[class*="particle"]']
      .forEach(function (sel) {
        try {
          document.querySelectorAll(sel).forEach(function (el) {
            el.style.setProperty('display', 'none', 'important');
          });
        } catch (e) {}
      });
  }

  function boot() {
    ensureBodyClass();
    if (!isOn()) return;
    buildChrome();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  setTimeout(boot, 400);
  setTimeout(hideDecor, 1200);
})();
