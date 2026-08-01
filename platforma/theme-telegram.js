(function () {
  if (document.getElementById('tg-sidebar')) return;

  function el(tag, attrs, html) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'className') n.className = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    if (html != null) n.innerHTML = html;
    return n;
  }

  var backdrop = el('div', { className: 'tg-backdrop', id: 'tg-backdrop' });
  var side = el('div', { className: 'tg-sidebar', id: 'tg-sidebar' });
  side.innerHTML =
    '<div class="tg-nav-label">Меню <span class="tg-beta-badge">TG</span></div>' +
    '<nav class="tg-nav">' +
    '<a class="tg-nav-item" href="/">🏠 Главная</a>' +
    '<a class="tg-nav-item" href="/videos">▶️ Видео</a>' +
    '<a class="tg-nav-item" href="/catalog">📺 Каталог</a>' +
    '<a class="tg-nav-item" href="/forum">💬 Форум</a>' +
    '<a class="tg-nav-item" href="/messages">✉️ Сообщения</a>' +
    '<a class="tg-nav-item" href="/platforma/studio/">📊 Студия</a>' +
    '<a class="tg-nav-item" href="/platforma/studio/analytics.php">📈 Аналитика</a>' +
    '<a class="tg-nav-item" href="/rating">🏆 Рейтинг</a>' +
    '</nav>' +
    '<div class="tg-nav-label">Стили</div>' +
    '<nav class="tg-nav">' +
    '<a class="tg-nav-item" href="/platforma/switch.php?mode=streamlife&redirect=/">StreamLife</a>' +
    '<a class="tg-nav-item" href="/platforma/switch.php?mode=platforma&redirect=/">Платформа</a>' +
    '<a class="tg-nav-item active" href="/platforma/switch.php?mode=telegram&redirect=/">Telegram</a>' +
    '</nav>';

  var bottom = el('div', { className: 'tg-bottom', id: 'tg-bottom' });
  bottom.innerHTML =
    '<a class="tg-bottom-item" href="/"><span class="ico">🏠</span>Главная</a>' +
    '<a class="tg-bottom-item" href="/videos"><span class="ico">▶️</span>Видео</a>' +
    '<a class="tg-bottom-item" href="/catalog"><span class="ico">📺</span>Каналы</a>' +
    '<a class="tg-bottom-item" href="/messages"><span class="ico">✉️</span>Чаты</a>' +
    '<a class="tg-bottom-item" href="/platforma/studio/"><span class="ico">📊</span>Студия</a>';

  function openSide() {
    side.classList.add('open');
    backdrop.classList.add('open');
    document.body.classList.add('tg-drawer-open');
  }
  function closeSide() {
    side.classList.remove('open');
    backdrop.classList.remove('open');
    document.body.classList.remove('tg-drawer-open');
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.body.appendChild(backdrop);
    document.body.appendChild(side);
    document.body.appendChild(bottom);
    backdrop.addEventListener('click', closeSide);

    var header = document.querySelector('.site-header, header, .header, nav') || document.body;
    var btn = el('button', { className: 'tg-menu-btn', type: 'button', title: 'Меню', id: 'tg-menu-btn' }, '☰');
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      if (side.classList.contains('open')) closeSide(); else openSide();
    });
    try {
      if (header.firstChild) header.insertBefore(btn, header.firstChild);
      else header.appendChild(btn);
    } catch (e) {
      document.body.insertBefore(btn, document.body.firstChild);
    }

    // highlight current bottom item
    var path = location.pathname || '/';
    bottom.querySelectorAll('.tg-bottom-item').forEach(function (a) {
      var href = a.getAttribute('href') || '';
      if (href === path || (href !== '/' && path.indexOf(href) === 0)) a.classList.add('active');
    });
  });
})();
