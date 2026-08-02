/**
 * Страховка: убирает соседние .user-prefix с одинаковым текстом в одном контейнере.
 * Работает на форуме, ресурсах, сообщениях, профиле — везде.
 */
(function () {
  function dedupe(root) {
    root = root || document;
    var nodes = root.querySelectorAll('.user-prefixes, .forum-post-author, .pg-name-row, a[href*="profile"]');
    nodes.forEach(function (box) {
      var seen = {};
      box.querySelectorAll('.user-prefix').forEach(function (el) {
        var key = (el.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (!key) return;
        if (seen[key]) {
          el.remove();
        } else {
          seen[key] = true;
        }
      });
    });
  }
  function run() { try { dedupe(document); } catch (e) {} }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
  // после poll-комментов
  setInterval(run, 4000);
})();
