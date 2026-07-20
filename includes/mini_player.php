<div id="mini-player" class="mini-player" style="display:none">
  <div class="mini-player-head">
    <a href="#" id="mini-player-title" class="mini-player-title-link"></a>
    <div class="mini-player-controls">
      <button type="button" id="mini-player-toggle" title="Свернуть/развернуть">▁</button>
      <button type="button" id="mini-player-close" title="Закрыть">✕</button>
    </div>
  </div>
  <div class="mini-player-body">
    <iframe id="mini-player-frame" src="" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
  </div>
</div>
<script>
(function () {
  var STORAGE_KEY = 'streamlive_miniplayer';
  var box = document.getElementById('mini-player');
  var frame = document.getElementById('mini-player-frame');
  var titleLink = document.getElementById('mini-player-title');
  var closeBtn = document.getElementById('mini-player-close');
  var toggleBtn = document.getElementById('mini-player-toggle');

  function getState() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { return null; }
  }
  function setState(state) {
    try {
      if (state) localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
      else localStorage.removeItem(STORAGE_KEY);
    } catch (e) { /* localStorage недоступен — просто не сохраняем */ }
  }

  function render() {
    var state = getState();
    var onOwnPage = window.__currentChannelSlug && state && state.slug === window.__currentChannelSlug;
    if (!state || onOwnPage) {
      box.style.display = 'none';
      if (frame.getAttribute('src')) frame.setAttribute('src', '');
      return;
    }
    box.style.display = '';
    if (frame.dataset.slug !== state.slug) {
      frame.src = '/embed.php?slug=' + encodeURIComponent(state.slug);
      frame.dataset.slug = state.slug;
    }
    titleLink.textContent = '▶ ' + (state.title || 'Смотреть канал');
    titleLink.href = '/channel.php?slug=' + encodeURIComponent(state.slug);
  }

  closeBtn.addEventListener('click', function () { setState(null); render(); });
  toggleBtn.addEventListener('click', function () { box.classList.toggle('collapsed'); });

  render();
})();
</script>
