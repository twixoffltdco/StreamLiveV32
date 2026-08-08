(function () {
  if (window.__SL_IDLE_INIT) return;
  window.__SL_IDLE_INIT = true;
  var IDLE_MS = 25 * 60 * 1000;
  var last = Date.now();
  var sleeping = false;
  function touch() {
    last = Date.now();
    if (sleeping) {
      sleeping = false;
      window.__SL_SLEEPING = false;
      document.documentElement.removeAttribute('data-sl-sleep');
      try { location.reload(); } catch (e) {}
    }
  }
  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (ev) {
    document.addEventListener(ev, touch, { passive: true, capture: true });
  });
  function sleep() {
    if (sleeping) return;
    sleeping = true;
    window.__SL_SLEEPING = true;
    document.documentElement.setAttribute('data-sl-sleep', '1');
    try {
      var maxId = setTimeout(function () {}, 0);
      for (var i = 0; i <= maxId; i++) {
        clearTimeout(i);
        clearInterval(i);
      }
    } catch (e) {}
  }
  setInterval(function () {
    if (!sleeping && (Date.now() - last) > IDLE_MS) sleep();
  }, 30000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) last = Date.now();
  });
})();
