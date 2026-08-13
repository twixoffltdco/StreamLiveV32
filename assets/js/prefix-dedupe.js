(function () {
  function dedupe(root) {
    root = root || document;
    root.querySelectorAll('[data-user-prefixes], .user-prefixes, .pfx-wrap').forEach(function (box) {
      var seen = {};
      box.querySelectorAll('.user-prefix, .pfx, [data-prefix-title]').forEach(function (el) {
        var t = (el.getAttribute('data-prefix-title') || el.textContent || '').trim().toLowerCase();
        if (!t) return;
        if (seen[t]) el.remove();
        else seen[t] = 1;
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { dedupe(); });
  } else {
    dedupe();
  }
})();
