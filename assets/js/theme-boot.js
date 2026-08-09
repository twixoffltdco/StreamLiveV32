/** Применить тему до отрисовки (из cookie). */
(function () {
  try {
    var m = document.cookie.match(/(?:^|; )site_color_mode=([^;]*)/);
    var p = document.cookie.match(/(?:^|; )pl_skin=([^;]*)/);
    var light = (m && decodeURIComponent(m[1]) === 'light') || (p && decodeURIComponent(p[1]) === 'light');
    var root = document.documentElement;
    if (light) {
      root.classList.add('light-mode');
      root.setAttribute('data-pl-skin', 'light');
      root.style.colorScheme = 'light';
    } else {
      root.classList.remove('light-mode');
      root.setAttribute('data-pl-skin', 'dark');
      root.style.colorScheme = 'dark';
    }
  } catch (e) {}
})();
