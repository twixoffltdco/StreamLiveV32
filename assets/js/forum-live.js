/**
 * Автоподхват новых тем/ответов на форуме при открытой вкладке.
 */
(function () {
  var el = document.getElementById('forum-live-root');
  if (!el) return;

  var categoryId = parseInt(el.getAttribute('data-category-id') || '0', 10) || 0;
  var afterTs = parseInt(el.getAttribute('data-after-ts') || '0', 10) || Math.floor(Date.now() / 1000) - 60;
  var list = document.querySelector('.forum-thread-list') || document.getElementById('forum-whats-new-list');
  var banner = document.getElementById('forum-live-banner');
  var seen = {};

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function showBanner(n) {
    if (!banner) return;
    banner.style.display = n > 0 ? 'block' : 'none';
    banner.textContent = n === 1 ? 'Появилась 1 новая тема / ответ — обновить' :
      ('Новых обновлений: ' + n + ' — нажмите, чтобы подгрузить');
  }

  var pending = [];

  function renderPending() {
    if (!list || !pending.length) return;
    pending.reverse().forEach(function (t) {
      if (seen[t.id]) return;
      seen[t.id] = true;
      var row = document.createElement('div');
      row.className = 'forum-thread-row forum-live-new';
      row.innerHTML =
        '<div class="forum-thread-main">' +
        (t.is_new_thread ? '<span class="pin-badge" style="background:#3b82f6">Новое</span> ' : '<span class="pin-badge" style="background:#22c55e">Ответ</span> ') +
        '<a href="/forum_thread.php?id=' + t.id + '" class="forum-thread-title">' + escapeHtml(t.title) + '</a>' +
        '<div style="color:var(--text-dim);font-size:12px;margin-top:2px">@' + escapeHtml(t.username) +
        (t.category_title && !categoryId ? ' · ' + escapeHtml(t.category_title) : '') +
        ' · ' + escapeHtml(t.last_post_at || t.created_at) + '</div></div>' +
        '<div class="forum-thread-stats"><div><b>' + (t.post_count || 0) + '</b><span>ответов</span></div></div>';
      list.insertBefore(row, list.firstChild);
    });
    pending = [];
    showBanner(0);
  }

  if (banner) {
    banner.addEventListener('click', function () { renderPending(); });
  }

  async function poll() {
    try {
      var url = '/forum_poll.php?after_ts=' + afterTs + '&category_id=' + categoryId + '&limit=15';
      var r = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      if (!r.ok) return;
      var d = await r.json();
      if (!d.ok) return;
      if (d.after_ts) afterTs = d.after_ts;
      (d.threads || []).forEach(function (t) {
        if (seen[t.id]) return;
        // не дублируем уже на странице
        if (document.querySelector('a.forum-thread-title[href="/forum_thread.php?id=' + t.id + '"]')) {
          seen[t.id] = true;
          return;
        }
        pending.push(t);
      });
      if (pending.length) {
        // авто-вставка без клика (как «что нового» в фоне)
        renderPending();
      }
    } catch (e) { /* ignore */ }
  }

  function pollSafe(){if(document.visibilityState==='hidden')return;try{poll();}catch(e){}}
  setInterval(pollSafe, 45000);
  // первый poll через 8 сек
  setTimeout(poll, 8000);
})();
