(function () {
  function pathNorm() {
    var p = (location.pathname || '/').replace(/\.php$/i, '');
    if (p.length > 1 && p.slice(-1) === '/') p = p.slice(0, -1);
    return p || '/';
  }
  function isAllowed(p) {
    // Игра + служебное
    if (p.indexOf('/flex/') === 0) return true;
    if (p.indexOf('/platforma/switch') === 0) return true;
    if (p.indexOf('/auth/') === 0) return true;
    if (p.indexOf('/api/') === 0) return true;
    // статика
    if (p.indexOf('/assets/') === 0) return true;
    return false;
  }
  function showGate() {
    if (document.getElementById('fx-flex-gate')) return;
    var el = document.createElement('div');
    el.id = 'fx-flex-gate';
    el.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#0b0e14;color:#fff;display:flex;align-items:center;justify-content:center;padding:24px;font-family:system-ui,sans-serif;text-align:center';
    el.innerHTML =
      '<div style="max-width:420px">' +
      '<div style="font-size:42px;margin-bottom:12px">🎮</div>' +
      '<h1 style="margin:0 0 12px;font-size:22px;font-weight:900">Стиль Flex — это игра</h1>' +
      '<p style="margin:0 0 18px;color:#94a3b8;line-height:1.5;font-size:15px">' +
      'Форум, каналы, видео и каталог в стиле Flex недоступны.<br>' +
      'Смотри контент в <b>StreamLife</b> или <b>Платформа</b>.<br>' +
      'Играть — только через <b>Flex World</b>.' +
      '</p>' +
      '<a href="/flex/world.php" style="display:inline-block;margin:6px;padding:14px 18px;background:#00a2ff;color:#fff;border-radius:12px;font-weight:900;text-decoration:none">Играть Flex World</a><br>' +
      '<a href="/platforma/switch.php?mode=streamlife&redirect=/" style="display:inline-block;margin:6px;padding:12px 16px;background:#334155;color:#fff;border-radius:12px;font-weight:800;text-decoration:none">StreamLife</a>' +
      '<a href="/platforma/switch.php?mode=platforma&redirect=/" style="display:inline-block;margin:6px;padding:12px 16px;background:#ff0000;color:#fff;border-radius:12px;font-weight:800;text-decoration:none">Платформа</a>' +
      '</div>';
    (document.body || document.documentElement).appendChild(el);
  }
  function boot() {
    try { document.body && document.body.classList.add('pl-theme-flex'); } catch (e) {}
    var p = pathNorm();
    if (isAllowed(p)) return;
    showGate();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
