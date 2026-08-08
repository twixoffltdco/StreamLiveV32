/**
 * Нет обложки → кадр через Canvas (не сохраняется на сервер).
 * Работает для mp4/webm/m3u8. Для iframe-платформ остаётся серверный thumb/placeholder.
 */
(function () {
  'use strict';

  function isPlaceholder(src) {
    if (!src) return true;
    src = String(src).trim();
    if (!src) return true;
    if (src.indexOf('placeholder') !== -1) return true;
    if (src.indexOf('data:,') === 0) return true;
    return false;
  }

  function canCanvasFromUrl(url) {
    if (!url) return false;
    return /\.(mp4|webm|ogg|mov)($|\?)/i.test(url) || /\.m3u8($|\?)/i.test(url);
  }

  function snap(img, mediaUrl) {
    if (!img || !mediaUrl) return;
    var v = document.createElement('video');
    v.muted = true;
    v.defaultMuted = true;
    v.playsInline = true;
    v.setAttribute('playsinline', '');
    v.preload = 'auto';
    try { v.crossOrigin = 'anonymous'; } catch (e) {}

    var done = false;
    function finish(ok) {
      if (done) return;
      done = true;
      try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {}
      if (!ok) {
        img.style.background = '#1a1a24';
      }
    }

    function draw() {
      if (done) return;
      try {
        var w = v.videoWidth || 0;
        var h = v.videoHeight || 0;
        if (w < 16 || h < 16) return;
        var c = document.createElement('canvas');
        c.width = Math.min(720, w);
        c.height = Math.round(c.width * (h / w));
        var ctx = c.getContext('2d');
        if (!ctx) { finish(false); return; }
        ctx.drawImage(v, 0, 0, c.width, c.height);
        img.src = c.toDataURL('image/jpeg', 0.78);
        img.style.objectFit = 'cover';
        finish(true);
      } catch (e) {
        finish(false);
      }
    }

    v.addEventListener('loadeddata', function () {
      try {
        var t = 0.5;
        if (v.duration && isFinite(v.duration) && v.duration > 1) {
          t = Math.min(Math.max(v.duration * 0.12, 0.3), 8);
        }
        v.currentTime = t;
      } catch (e) {
        setTimeout(draw, 250);
      }
    });
    v.addEventListener('seeked', draw);
    v.addEventListener('error', function () { finish(false); });
    setTimeout(function () { if (!done) finish(false); }, 10000);
    v.src = mediaUrl;
  }

  function process(root) {
    root = root || document;
    var nodes = root.querySelectorAll('[data-thumb-canvas]');
    for (var i = 0; i < nodes.length; i++) {
      var box = nodes[i];
      if (box.getAttribute('data-thumb-done')) continue;
      var img = box.tagName === 'IMG' ? box : box.querySelector('img');
      if (!img) continue;
      var cur = (img.getAttribute('src') || '').trim();
      if (!isPlaceholder(cur)) {
        box.setAttribute('data-thumb-done', '1');
        continue;
      }
      var media = (box.getAttribute('data-thumb-src') || '').trim();
      if (!media) {
        media = (img.getAttribute('data-media') || '').trim();
      }
      box.setAttribute('data-thumb-done', '1');
      if (canCanvasFromUrl(media)) {
        snap(img, media);
      }
    }
  }

  function boot() { process(document); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  window.slVideoThumbCanvas = process;
})();
