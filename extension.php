<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/extension_build.php';

$base = extension_site_base();
$host = parse_url($base, PHP_URL_HOST) ?: 'сайт';
$pageTitle = 'Скачать расширение StreamLive';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:720px;padding:20px 16px 48px">
  <h1 style="margin:0 0 8px;font-size:1.5rem">🧩 Расширение StreamLive</h1>
  <p style="color:var(--text-dim,#aaa);margin:0 0 16px">
    Для <b><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></b>
  </p>

  <div id="sl-ext-status" class="card" style="padding:14px;margin-bottom:16px;border-radius:12px;background:var(--card,#1a1a1a);border:1px solid var(--border,#333)">
    <div id="sl-ext-msg">Проверяем, установлено ли расширение…</div>
  </div>

  <p id="sl-ext-download-wrap" style="margin:0 0 20px">
    <a class="btn btn-primary" id="sl-ext-dl" href="/extension_download.php"
      style="display:inline-flex;align-items:center;gap:8px;padding:12px 18px;font-weight:700;text-decoration:none;border-radius:12px;background:#e50914;color:#fff">
      ⬇ Скачать расширение (ZIP)
    </a>
  </p>

  <div class="card" style="padding:16px;margin-bottom:14px;border-radius:12px;background:var(--card,#1a1a1a);border:1px solid var(--border,#333)">
    <h2 style="font-size:1.1rem;margin:0 0 10px">Если уже установлено</h2>
    <p style="margin:0;line-height:1.5;color:var(--text-dim,#aaa)">
      Жми иконку <b>StreamLive</b> на панели браузера — откроется заставка, проверка сайта, монетки и «Открыть платформу».
      Не нужно скачивать ZIP снова (только при обновлении версии).
    </p>
  </div>

  <div class="card" style="padding:16px;margin-bottom:14px;border-radius:12px;background:var(--card,#1a1a1a);border:1px solid var(--border,#333)">
    <h2 style="font-size:1.1rem;margin:0 0 10px">Установка (Chrome / Яндекс / Opera / Edge)</h2>
    <ol style="margin:0;padding-left:1.2em;line-height:1.55">
      <li>Скачай ZIP и распакуй.</li>
      <li><code>chrome://extensions</code> → режим разработчика.</li>
      <li>«Загрузить распакованное» → папка с <code>manifest.json</code>.</li>
      <li>Иконка на панели → заставка → монетки / открыть сайт.</li>
    </ol>
  </div>
</div>
<script>
(function () {
  var msg = document.getElementById('sl-ext-msg');
  var wrap = document.getElementById('sl-ext-download-wrap');
  function installed(v) {
    msg.innerHTML = '✅ Расширение <b>уже установлено</b>' + (v ? ' (v' + v + ')' : '') +
      '. Открой иконку StreamLive на панели браузера — там монетки и вход на платформу.';
    if (wrap) {
      wrap.innerHTML = '<span style="color:var(--text-dim,#aaa)">Скачивать снова нужно только для обновления. Актуальный ZIP: <a href="/extension_download.php">скачать v1.1.1</a></span>';
    }
  }
  function notInstalled() {
    msg.textContent = 'Расширение не обнаружено в этом браузере. Скачай ZIP и установи (инструкция ниже).';
  }
  if (document.documentElement.getAttribute('data-sl-extension') || window.SL_EXTENSION) {
    installed((window.SL_EXTENSION && window.SL_EXTENSION.version) || document.documentElement.getAttribute('data-sl-extension'));
    return;
  }
  window.addEventListener('sl-extension-ready', function (e) {
    installed(e.detail && e.detail.version);
  });
  setTimeout(function () {
    if (!(document.documentElement.getAttribute('data-sl-extension') || window.SL_EXTENSION)) notInstalled();
  }, 800);
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
