(function () {
  if (document.getElementById('ph-sidebar')) return;
  function el(tag, attrs, html) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'className') n.className = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    if (html != null) n.innerHTML = html;
    return n;
  }
  var backdrop = el('div', { className: 'ph-backdrop', id: 'ph-backdrop' });
  var side = el('div', { className: 'ph-sidebar', id: 'ph-sidebar' });
  side.innerHTML =
    '<div class="ph-nav-label">ProHub Nexus <span class="ph-badge">NEXUS</span></div>' +
    '<nav>' +
    '<a class="ph-nav-item" href="/">🏠 Главная</a>' +
    '<a class="ph-nav-item" href="/forum">💬 Форум</a>' +
    '<a class="ph-nav-item" href="/videos">▶️ Видео</a>' +
    '<a class="ph-nav-item" href="/catalog">📺 Каналы</a>' +
    '<a class="ph-nav-item" href="/resources">📦 Ресурсы</a>' +
    '<a class="ph-nav-item" href="/favorites">⭐ Избранное</a>' +
    '</nav>' +
    '<div class="ph-nav-label">Стили</div>' +
    '<nav>' +
    '<a class="ph-nav-item" href="/platforma/switch.php?mode=streamlife&redirect=/">StreamLife</a>' +
    '<a class="ph-nav-item" href="/platforma/switch.php?mode=platforma&redirect=/">Платформа</a>' +
    '<a class="ph-nav-item" href="/platforma/switch.php?mode=telegram&redirect=/">Telegram</a>' +
    '<a class="ph-nav-item active" href="/platforma/switch.php?mode=prohub&redirect=/">ProHub Nexus</a>' +
    '</nav>';
  var bottom = el('div', { className: 'ph-bottom', id: 'ph-bottom' });
  bottom.innerHTML =
    '<a class="ph-bottom-item" href="/"><span>🏠</span>Главная</a>' +
    '<a class="ph-bottom-item" href="/forum"><span>💬</span>Форум</a>' +
    '<a class="ph-bottom-item" href="/videos"><span>▶️</span>Видео</a>' +
    '<a class="ph-bottom-item" href="/catalog"><span>📺</span>Каналы</a>' +
    '<a class="ph-bottom-item" href="/favorites"><span>⭐</span>Избранное</a>';

  function openSide() {
    side.classList.add('open'); backdrop.classList.add('open');
  }
  function closeSide() {
    side.classList.remove('open'); backdrop.classList.remove('open');
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.body.appendChild(backdrop);
    document.body.appendChild(side);
    document.body.appendChild(bottom);
    backdrop.addEventListener('click', closeSide);
    var header = document.querySelector('.site-header, header, .header, nav') || document.body;
    var btn = el('button', { className: 'ph-menu-btn', type: 'button', title: 'Меню' }, '☰');
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
  });
})();
