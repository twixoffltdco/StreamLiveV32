(function () {
  var list = document.querySelector('.forum-thread-list, #forum-threads, [data-forum-live]');
  if (!list) return;
  var afterTs = parseInt(list.getAttribute('data-after-ts') || '0', 10) || Math.floor(Date.now() / 1000) - 60;
  var cat = parseInt(list.getAttribute('data-category-id') || '0', 10) || 0;
  var seen = {}, busy = false;
  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function poll() {
    if (window.__slHitsAllowed && !window.__slHitsAllowed()) return;
    if (busy) return;
    busy = true;
    fetch('/forum_poll.php?after_ts=' + afterTs + '&category_id=' + cat + '&limit=10', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.status === 429 || !r.ok ? null : r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        if (d.after_ts) afterTs = d.after_ts;
        (d.threads || []).forEach(function (t) {
          if (seen[t.id]) return;
          seen[t.id] = 1;
          var row = document.createElement('div');
          row.className = 'forum-thread-row forum-live-new';
          row.innerHTML = '<div class="forum-thread-main"><a href="/forum_thread.php?id=' + t.id + '" class="forum-thread-title">' + esc(t.title) + '</a></div>';
          list.insertBefore(row, list.firstChild);
        });
      })
      .catch(function () {})
      .finally(function () { busy = false; });
  }
  setInterval(poll, 180000);
})();
