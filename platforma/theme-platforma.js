/**
 * Платформа ≈ plvideo: chrome + светлая/тёмная + акценты + баннер TG
 */
(function () {
  'use strict';

  function isOn() {
    return document.documentElement.classList.contains('pl-theme-platforma') ||
      (document.body && document.body.classList.contains('pl-theme-platforma'));
  }

  function cookieGet(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }
  function cookieSet(name, val) {
    document.cookie = name + '=' + encodeURIComponent(val) + '; path=/; max-age=31536000; SameSite=Lax';
  }

  function applySkin() {
    // exposed for ☀️ button
    var skin = cookieGet('pl_skin') || cookieGet('site_color_mode') || 'dark';
    if (skin !== 'light') skin = 'dark';
    var accent = cookieGet('pl_accent') || 'red';
    var preset = cookieGet('pl_preset') || '';
    var root = document.documentElement;
    var body = document.body;

    root.setAttribute('data-pl-skin', skin);
    root.setAttribute('data-pl-accent', accent);
    if (preset) root.setAttribute('data-pl-preset', preset);
    else root.removeAttribute('data-pl-preset');

    if (skin === 'light') {
      root.classList.add('light-mode');
      root.style.setProperty('--pl-bg', '#ffffff');
      root.style.setProperty('--pl-elev', '#f2f2f2');
      root.style.setProperty('--pl-hover', '#e5e5e5');
      root.style.setProperty('--pl-text', '#0f0f0f');
      root.style.setProperty('--pl-muted', '#606060');
      root.style.setProperty('--pl-border', '#e5e5e5');
      root.style.setProperty('--pl-chip', '#f2f2f2');
      root.style.setProperty('--pl-chip-on-bg', '#0f0f0f');
      root.style.setProperty('--pl-chip-on-fg', '#ffffff');
      root.style.setProperty('--bg', '#ffffff');
      root.style.setProperty('--text', '#0f0f0f');
      root.style.colorScheme = 'light';
    } else {
      root.classList.remove('light-mode');
      root.style.setProperty('--pl-bg', '#0f0f0f');
      root.style.setProperty('--pl-elev', '#272727');
      root.style.setProperty('--pl-hover', '#3f3f3f');
      root.style.setProperty('--pl-text', '#f1f1f1');
      root.style.setProperty('--pl-muted', '#aaaaaa');
      root.style.setProperty('--pl-border', '#303030');
      root.style.setProperty('--pl-chip', '#272727');
      root.style.setProperty('--pl-chip-on-bg', '#f1f1f1');
      root.style.setProperty('--pl-chip-on-fg', '#0f0f0f');
      root.style.colorScheme = 'dark';
    }

    if (body) {
      body.setAttribute('data-pl-skin', skin);
      body.setAttribute('data-pl-accent', accent);
      if (preset) body.setAttribute('data-pl-preset', preset);
      else body.removeAttribute('data-pl-preset');
      body.style.setProperty('background', skin === 'light' ? '#ffffff' : '#0f0f0f', 'important');
      body.style.setProperty('color', skin === 'light' ? '#0f0f0f' : '#f1f1f1', 'important');
      body.style.setProperty('--pl-bg', skin === 'light' ? '#ffffff' : '#0f0f0f');
      body.style.setProperty('--pl-text', skin === 'light' ? '#0f0f0f' : '#f1f1f1');
    }
    try { paintSidebarLight(); } catch (e) {}
  }

  function iconSvg(name) {
    var paths = {
      home: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
      shorts: 'M10 14.65l5.47-4.02c.4-.29.4-.92 0-1.21L10 5.35c-.5-.37-1.2-.01-1.2.61v8.08c0 .62.7.98 1.2.61zM17.5 12c0 3.03-2.47 5.5-5.5 5.5S6.5 15.03 6.5 12 8.97 6.5 12 6.5s5.5 2.47 5.5 5.5z',
      play: 'M10 16.5l6-4.5-6-4.5v9zM12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z',
      tv: 'M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 1.99-.9 1.99-2L23 5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z',
      chat: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z',
      docs: 'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z',
      star: 'M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z',
      mail: 'M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z',
      menu: 'M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z',
      studio: 'M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z',
      fire: 'M13.5.67s.74 2.65.74 4.8c0 2.06-1.35 3.73-3.41 3.73-2.07 0-3.63-1.67-3.63-3.73l.03-.36C5.21 7.51 4 10.62 4 14c0 4.42 3.58 8 8 8s8-3.58 8-8C20 8.61 17.41 3.8 13.5.67zM11.71 19c-1.78 0-3.22-1.4-3.22-3.14 0-1.62 1.05-2.76 2.81-3.12 1.77-.36 3.6-1.21 4.62-2.58.39 1.29.59 2.65.59 4.04 0 2.65-2.15 4.8-4.8 4.8z'
    };
    var d = paths[name] || paths.home;
    return '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="' + d + '"/></svg>';
  }

  var MAIN = [
    { href: '/', label: 'Главная', icon: 'home' },
    { href: '/shorts.php', label: 'Shorts', icon: 'shorts' },
    { href: '/videos.php', label: 'Видео', icon: 'play' },
    { href: '/catalog.php', label: 'Каналы', icon: 'tv' },
    { href: '/forum_whats_new.php', label: 'Что нового', icon: 'fire' }
  ];
  var MORE = [
    { href: '/forum.php', label: 'Форум', icon: 'chat' },
    { href: '/services', label: 'Сервисы', icon: 'docs' },
    { href: '/resources.php', label: 'Ресурсы', icon: 'docs' },
    { href: '/rating.php', label: 'Рейтинг', icon: 'star' },
    { href: '/messages.php', label: 'Мессенджер', icon: 'mail' }
  ];
  var STUDIO = [
    { href: '/platforma/studio/', label: 'Студия', icon: 'studio' },
    { href: '/platforma/studio/import.php', label: 'Импорт', icon: 'play' }
  ];

  function isActive(href) {
    var path = location.pathname || '/';
    if (href === '/') return path === '/' || path === '/index.php';
    return path.indexOf(href.replace(/\.php$/, '')) !== -1 || path.indexOf(href) !== -1;
  }

  function makeItem(l) {
    var a = document.createElement('a');
    a.href = l.href;
    a.className = 'pl-yt-nav-item' + (isActive(l.href) ? ' active' : '');
    a.innerHTML = iconSvg(l.icon) + '<span>' + l.label + '</span>';
    return a;
  }

  function buildSkinPanel() {
    var panel = document.createElement('div');
    panel.className = 'pl-skin-panel';
    var skin = cookieGet('pl_skin') || 'dark';
    var accent = cookieGet('pl_accent') || 'red';
    var preset = cookieGet('pl_preset') || '';

    function title(txt) {
      var d = document.createElement('div');
      d.className = 'pl-skin-title';
      d.textContent = txt;
      return d;
    }

    panel.appendChild(title('Тема'));
    var row1 = document.createElement('div');
    row1.className = 'pl-skin-row';
    [['dark', 'Тёмная'], ['light', 'Светлая']].forEach(function (pair) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'pl-skin-btn' + (skin === pair[0] && !preset ? ' on' : '');
      b.setAttribute('data-skin', pair[0]);
      b.title = pair[1];
      b.addEventListener('click', function () {
        cookieSet('pl_skin', pair[0]);
        cookieSet('pl_preset', '');
        applySkin();
        // без reload — cookie + CSS vars
      });
      row1.appendChild(b);
    });
    panel.appendChild(row1);

    panel.appendChild(title('Акцент'));
    var row2 = document.createElement('div');
    row2.className = 'pl-skin-row';
    ['red', 'blue', 'orange', 'green', 'purple', 'pink'].forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'pl-skin-btn' + (accent === a && !preset ? ' on' : '');
      b.setAttribute('data-accent', a);
      b.title = a;
      b.addEventListener('click', function () {
        cookieSet('pl_accent', a);
        cookieSet('pl_preset', '');
        applySkin();
        // без reload — cookie + CSS vars
      });
      row2.appendChild(b);
    });
    panel.appendChild(row2);

    panel.appendChild(title('Пресеты'));
    var row3 = document.createElement('div');
    row3.className = 'pl-skin-row';
    row3.style.flexWrap = 'wrap';
    var presets = [
      ['', 'Стандарт'],
      ['midnight', 'Midnight'],
      ['gray', 'Gray'],
      ['cream', 'Cream'],
      ['ocean', 'Ocean'],
      ['forest', 'Forest'],
      ['contrast', 'Contrast'],
      ['rose', 'Rose'],
      ['sunset', 'Sunset']
    ];
    presets.forEach(function (pr) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = pr[1];
      b.title = pr[0] || 'default';
      b.style.cssText = 'padding:6px 10px;border-radius:14px;border:1px solid var(--pl-border);background:var(--pl-elev);color:var(--pl-text);font-size:11px;cursor:pointer;';
      if ((pr[0] === '' && !preset) || preset === pr[0]) {
        b.style.background = 'var(--pl-chip-on-bg)';
        b.style.color = 'var(--pl-chip-on-fg)';
      }
      b.addEventListener('click', function () {
        cookieSet('pl_preset', pr[0]);
        if (pr[0] === 'cream') cookieSet('pl_skin', 'light');
        else if (pr[0] !== '') cookieSet('pl_skin', 'dark');
        applySkin();
        // без reload — cookie + CSS vars
      });
      row3.appendChild(b);
    });
    panel.appendChild(row3);
    return panel;
  }

  function addTgBanner() {
    if (document.getElementById('pl-tg-banner')) return;
    try {
      if (sessionStorage.getItem('pl_tg_banner_hide') === '1') return;
    } catch (e) {}

    var tgUrl = (window.PL_TG_URL || 'https://t.me/platforma_offcial');
    var bar = document.createElement('div');
    bar.id = 'pl-tg-banner';
    bar.className = 'pl-tg-banner';
    bar.setAttribute('role', 'status');
    bar.innerHTML =
      '<div class="pl-tg-banner-inner">' +
        '<span style="font-size:18px">📢</span>' +
        '<div class="pl-tg-banner-text">' +
          '<b>Бета</b> · Сообщайте о багах в Telegram · ' +
          '<a href="' + tgUrl + '" target="_blank" rel="noopener">t.me/platforma_offcial</a>' +
        '</div>' +
        '<a class="pl-tg-banner-btn" href="' + tgUrl + '" target="_blank" rel="noopener">Написать</a>' +
        '<button type="button" class="pl-tg-banner-x" title="Скрыть" aria-label="Скрыть">×</button>' +
      '</div>';
    document.body.appendChild(bar);
    document.body.classList.add('pl-has-tg-banner');
    var close = bar.querySelector('.pl-tg-banner-x');
    if (close) {
      close.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        bar.remove();
        document.body.classList.remove('pl-has-tg-banner');
        try { sessionStorage.setItem('pl_tg_banner_hide', '1'); } catch (err) {}
      });
    }
  }

  function addBetaBadge() {
    if (document.getElementById('pl-beta-badge')) return;
    var candidates = document.querySelectorAll('header a, .header a, .navbar a, .logo, .site-logo, a.brand');
    var target = null;
    candidates.forEach(function (el) {
      if (target) return;
      var t = (el.textContent || '').trim();
      if (/stream|платформ|platform|live/i.test(t) || el.querySelector('img')) target = el;
    });
    if (!target) {
      var h = document.querySelector('header, .header, .navbar');
      if (h) target = h.querySelector('a');
    }
    if (!target) return;
    var badge = document.createElement('span');
    badge.id = 'pl-beta-badge';
    badge.className = 'pl-beta-badge';
    badge.textContent = 'Бета';
    target.appendChild(document.createTextNode(' '));
    target.appendChild(badge);
  }

  function buildChrome() {
    if (document.getElementById('pl-yt-sidebar')) return;

    var btn = document.createElement('button');
    btn.id = 'pl-yt-menu-btn';
    btn.type = 'button';
    btn.className = 'pl-yt-menu-btn';
    btn.setAttribute('aria-label', 'Меню');
    btn.innerHTML = iconSvg('menu');

    var sb = document.createElement('aside');
    sb.id = 'pl-yt-sidebar';
    sb.className = 'pl-yt-sidebar';
    // immediate light paint (don't wait CSS vars)
    var _sk = cookieGet('pl_skin') || cookieGet('site_color_mode') || '';
    if (_sk === 'light' || document.documentElement.classList.contains('light-mode')) {
      sb.style.setProperty('background', '#ffffff', 'important');
      sb.style.setProperty('background-color', '#ffffff', 'important');
      sb.style.setProperty('color', '#0f0f0f', 'important');
    }
    var nav = document.createElement('nav');
    nav.className = 'pl-yt-nav';

    MAIN.forEach(function (l) { nav.appendChild(makeItem(l)); });

    var div1 = document.createElement('div');
    div1.className = 'pl-yt-nav-div';
    nav.appendChild(div1);

    // Авторизация: из PHP window.PL_USER (не гадание по DOM)
    var plUser = window.PL_USER || {};
    var logged = !!plUser.logged || !!(document.querySelector('a[href*="logout"], .user-menu, [data-user-id]'));
    if (!logged) {
      var login = document.createElement('a');
      login.href = '/auth/login.php?next=' + encodeURIComponent(location.pathname + location.search);
      login.className = 'pl-yt-login-pill';
      login.textContent = '→ Войти';
      nav.appendChild(login);
      var hint = document.createElement('div');
      hint.style.cssText = 'padding:4px 12px 12px;font-size:12px;color:var(--pl-muted);line-height:1.4';
      hint.textContent = 'Войдите, чтобы ставить «Нравится», писать комментарии и добавлять в избранное.';
      nav.appendChild(hint);
    } else {
      var name = (plUser.name || 'Аккаунт').toString();
      var userBlock = document.createElement('div');
      userBlock.className = 'pl-user-block';
      userBlock.innerHTML =
        '<div class="pl-user-name">👤 ' + name.replace(/</g, '&lt;') + '</div>';
      nav.appendChild(userBlock);
      // Подписки = Избранное (каналы)
      var fav = document.createElement('a');
      fav.href = '/favorites';
      fav.className = 'pl-yt-nav-item' + (isActive('/favorites') ? ' active' : '');
      fav.innerHTML = iconSvg('star') + '<span>Подписки</span>';
      nav.appendChild(fav);
      // YouTube-style: каналы подписок в сайдбаре
      // YouTube-style: подписки всегда видны (избранные каналы)
      var subs = (plUser.subs || []);
      var subLab = document.createElement('div');
      subLab.className = 'pl-yt-nav-label';
      subLab.textContent = 'Подписки';
      nav.appendChild(subLab);
      var strip = document.createElement('div');
      strip.className = 'pl-subs-strip';
      if (subs.length) {
        subs.slice(0, 24).forEach(function (ch) {
          var a = document.createElement('a');
          a.className = 'pl-subs-strip-item';
          a.href = ch.slug ? ('/channel.php?slug=' + encodeURIComponent(ch.slug)) : ('/channel.php?id=' + (ch.id || ''));
          a.title = ch.title || '';
          var img = document.createElement('img');
          img.src = ch.avatar || '/assets/img/avatar-placeholder.png';
          img.alt = '';
          img.loading = 'lazy';
          img.onerror = function () { this.src = '/assets/img/avatar-placeholder.png'; };
          var sp = document.createElement('span');
          sp.textContent = (ch.title || 'Канал').slice(0, 18);
          a.appendChild(img);
          a.appendChild(sp);
          strip.appendChild(a);
        });
      } else {
        var empty = document.createElement('div');
        empty.className = 'pl-subs-empty-side';
        empty.textContent = 'Нет избранных каналов';
        strip.appendChild(empty);
      }
      nav.appendChild(strip);
      var all = document.createElement('a');
      all.className = 'pl-subs-strip-all';
      all.href = '/favorites';
      all.textContent = 'Все подписки →';
      nav.appendChild(all);
      // догрузка подписок с сервера, если PHP не отдал
      if (!subs.length && plUser.logged) {
        fetch('/api/pl_subs.php', { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d || !d.subs || !d.subs.length) return;
            strip.innerHTML = '';
            d.subs.slice(0, 24).forEach(function (ch) {
              var a = document.createElement('a');
              a.className = 'pl-subs-strip-item';
              a.href = ch.slug ? ('/channel.php?slug=' + encodeURIComponent(ch.slug)) : ('/channel.php?id=' + (ch.id || ''));
              a.title = ch.title || '';
              var img = document.createElement('img');
              img.src = ch.avatar || '/assets/img/avatar-placeholder.png';
              img.alt = '';
              img.loading = 'lazy';
              img.onerror = function () { this.src = '/assets/img/avatar-placeholder.png'; };
              var sp = document.createElement('span');
              sp.textContent = (ch.title || 'Канал').slice(0, 18);
              a.appendChild(img);
              a.appendChild(sp);
              strip.appendChild(a);
            });
          })
          .catch(function () {});
      }


      var cont = document.createElement('a');
      cont.href = '/continue_watching.php';
      cont.className = 'pl-yt-nav-item';
      cont.innerHTML = iconSvg('play') + '<span>Продолжить</span>';
      nav.appendChild(cont);
      var msg = document.createElement('a');
      msg.href = '/messages';
      msg.className = 'pl-yt-nav-item';
      msg.innerHTML = iconSvg('mail') + '<span>Сообщения</span>';
      nav.appendChild(msg);
      var prof = document.createElement('a');
      prof.href = '/dashboard.php';
      prof.className = 'pl-yt-nav-item';
      prof.innerHTML = iconSvg('studio') + '<span>Кабинет</span>';
      nav.appendChild(prof);
      var out = document.createElement('a');
      out.href = '/auth/logout.php';
      out.className = 'pl-yt-nav-item pl-logout';
      out.innerHTML = iconSvg('menu') + '<span>Выйти</span>';
      nav.appendChild(out);
    }
    var div2 = document.createElement('div');
    div2.className = 'pl-yt-nav-div';
    nav.appendChild(div2);

    var lab = document.createElement('div');
    lab.className = 'pl-yt-nav-label';
    lab.textContent = 'Навигатор';
    nav.appendChild(lab);
    MORE.forEach(function (l) { nav.appendChild(makeItem(l)); });

    var div3 = document.createElement('div');
    div3.className = 'pl-yt-nav-div';
    nav.appendChild(div3);
    var lab2 = document.createElement('div');
    lab2.className = 'pl-yt-nav-label';
    lab2.textContent = 'Студия';
    nav.appendChild(lab2);
    STUDIO.forEach(function (l) { nav.appendChild(makeItem(l)); });

    var div4 = document.createElement('div');
    div4.className = 'pl-yt-nav-div';
    nav.appendChild(div4);
    nav.appendChild(buildSkinPanel());

    sb.appendChild(nav);
    var foot = document.createElement('div');
    foot.className = 'pl-yt-side-foot';
    foot.innerHTML = '<p>Платформа · StreamLife</p>';
    sb.appendChild(foot);

    var back = document.createElement('div');
    back.id = 'pl-yt-backdrop';
    back.className = 'pl-yt-backdrop';

    var bottom = document.createElement('nav');
    bottom.id = 'pl-yt-bottom';
    bottom.className = 'pl-yt-bottom';
    [
      { href: '/', label: 'Главная', icon: 'home' },
      { href: '/shorts.php', label: 'Shorts', icon: 'shorts' },
      { href: '/videos.php', label: 'Видео', icon: 'play' },
      { href: '/platforma/studio/', label: 'Студия', icon: 'studio' },
      { href: '#menu', label: 'Ещё', icon: 'menu' }
    ].forEach(function (l) {
      var a = document.createElement('a');
      a.href = l.href;
      a.className = 'pl-yt-bottom-item' + (isActive(l.href) ? ' active' : '');
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
    }
    btn.addEventListener('click', function () { toggle(); });
    back.addEventListener('click', function () { toggle(false); });

    addBetaBadge();
    addTgBanner();
    glassifyNav();
  }


  /** Liquid glass для шапочного меню (Форум, Сервисы…) */
  function glassifyNav() {
    var links = document.querySelector('.nav-links, .navbar .nav-links, header .nav-links, .header-nav');
    if (!links) {
      // собрать ссылки вручную
      var header = document.querySelector('header, .header, .navbar');
      if (!header) return;
      var candidates = header.querySelectorAll('a');
      var glass = document.getElementById('pl-glass-nav');
      if (glass) return;
      glass = document.createElement('div');
      glass.id = 'pl-glass-nav';
      glass.className = 'pl-glass-nav';
      var want = /форум|сервис|ресурс|рейтинг|help|помощь|каталог|сообщени|избран/i;
      candidates.forEach(function (a) {
        var txt = (a.textContent || '').trim();
        if (want.test(txt) || want.test(a.getAttribute('href') || '')) {
          var c = a.cloneNode(true);
          glass.appendChild(c);
        }
      });
      if (glass.childNodes.length) {
        header.appendChild(glass);
      }
      return;
    }
    links.classList.add('pl-glass-nav');
  
    // mobile: duplicate key links into sidebar if missing
    var sb = document.getElementById('pl-yt-sidebar');
    if (sb && !document.getElementById('pl-mobile-extra-nav')) {
      var extra = document.createElement('div');
      extra.id = 'pl-mobile-extra-nav';
      extra.innerHTML = '<div class="pl-yt-nav-div"></div><div class="pl-yt-nav-label">Меню</div>';
      [['/forum','Форум'],['/services','Сервисы'],['/resources.php','Ресурсы'],['/rating.php','Рейтинг'],['/favorites','Избранное']].forEach(function(pair){
        var a = document.createElement('a');
        a.href = pair[0];
        a.className = 'pl-yt-nav-item';
        a.textContent = pair[1];
        extra.appendChild(a);
      });
      var nav = sb.querySelector('.pl-yt-nav');
      if (nav) nav.appendChild(extra);
    }
}


  function paintSidebarLight() {
    var skin = (document.body && document.body.getAttribute('data-pl-skin')) ||
      document.documentElement.getAttribute('data-pl-skin') || '';
    var sb = document.getElementById('pl-yt-sidebar');
    if (!sb) return;
    if (skin === 'light') {
      sb.style.setProperty('background', '#ffffff', 'important');
      sb.style.setProperty('background-color', '#ffffff', 'important');
      sb.style.setProperty('color', '#0f0f0f', 'important');
      sb.style.setProperty('border-right-color', '#e5e5e5', 'important');
      sb.querySelectorAll('a, span, div, button, .pl-yt-nav-label, .pl-yt-side-foot').forEach(function (el) {
        if (el.classList && el.classList.contains('pl-yt-login-pill')) return;
        el.style.setProperty('color', '#0f0f0f', 'important');
      });
      sb.querySelectorAll('svg, path').forEach(function (el) {
        el.style.setProperty('fill', '#0f0f0f', 'important');
        el.style.setProperty('color', '#0f0f0f', 'important');
      });
      sb.querySelectorAll('.pl-yt-nav-item.active, .pl-yt-nav-item:hover').forEach(function (el) {
        el.style.setProperty('background', '#f2f2f2', 'important');
      });
    } else {
      sb.style.removeProperty('background');
      sb.style.removeProperty('background-color');
      sb.style.removeProperty('color');
    }
  }

  function boot() {
    applySkin();
    if (!isOn()) return;
    buildChrome();
    paintSidebarLight();
  }

  // apply skin ASAP to avoid flash
  window.__plApplySkin = applySkin;
  applySkin();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  setTimeout(boot, 50);
  setTimeout(boot, 400);
  setTimeout(paintSidebarLight, 0);
  setTimeout(paintSidebarLight, 100);
  setTimeout(paintSidebarLight, 500);
  setTimeout(paintSidebarLight, 1500);
  var _pln = 0;
  var _pli = setInterval(function(){ paintSidebarLight(); if (++_pln > 20) clearInterval(_pli); }, 250);
})();
