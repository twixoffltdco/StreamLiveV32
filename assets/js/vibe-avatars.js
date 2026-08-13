
(function () {
  function frameClass() {
    var m = document.body && document.body.getAttribute('data-vibe-frame');
    return m || 'vibe-frame-sept3';
  }
  function enhance() {
    var cls = frameClass();
    var nodes = document.querySelectorAll(
      'img[src*="avatar"], img[src*="gravatar"], img[src*="githubusercontent"]'
    );
    nodes.forEach(function (img) {
      // НЕ трогаем аватар профиля — ломает layout
      if (img.classList.contains('pg-avatar')) return;
      if (img.closest('.pg-hero') || img.closest('.pg-hero-main')) return;
      if (img.closest('.vibe-avatar-wrap')) return;
      var w = img.offsetWidth || parseInt(img.getAttribute('width'), 10) || 32;
      if (w < 20 || w > 120) return;
      var h = img.offsetHeight || w;
      var wrap = document.createElement('span');
      wrap.className = 'vibe-avatar-wrap ' + cls;
      wrap.style.width = w + 'px';
      wrap.style.height = h + 'px';
      img.style.width = '100%';
      img.style.height = '100%';
      img.style.borderRadius = '50%';
      img.style.objectFit = 'cover';
      img.style.display = 'block';
      if (img.parentNode) {
        img.parentNode.insertBefore(wrap, img);
        wrap.appendChild(img);
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance);
  else enhance();
})();
