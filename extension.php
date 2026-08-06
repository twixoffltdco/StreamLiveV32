<?php
/**
 * Страница: скачать расширение + туториал. Домен подставляется сам.
 */
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
    Для <b><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></b> — кнопка на панели браузера открывает платформу в окне на весь экран.
  </p>

  <p style="margin:0 0 20px">
    <a class="btn btn-primary" href="/extension_download.php" style="display:inline-flex;align-items:center;gap:8px;padding:12px 18px;font-weight:700;text-decoration:none;border-radius:12px;background:#e50914;color:#fff">
      ⬇ Скачать расширение (ZIP)
    </a>
  </p>

  <div class="card" style="padding:16px;margin-bottom:14px;border-radius:12px;background:var(--card,#1a1a1a);border:1px solid var(--border,#333)">
    <h2 style="font-size:1.1rem;margin:0 0 10px">Chrome / Яндекс / Opera / Edge</h2>
    <ol style="margin:0;padding-left:1.2em;line-height:1.55;color:var(--text,#eee)">
      <li>Скачай ZIP и <b>распакуй</b> в любую папку.</li>
      <li>Открой <code>chrome://extensions</code> (Яндекс: <code>browser://extensions</code>).</li>
      <li>Включи <b>«Режим разработчика»</b>.</li>
      <li>«Загрузить распакованное расширение» → выбери папку с <code>manifest.json</code>.</li>
      <li>Нажми иконку StreamLive на панели — откроется <b><?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8') ?></b>.</li>
    </ol>
  </div>

  <div class="card" style="padding:16px;margin-bottom:14px;border-radius:12px;background:var(--card,#1a1a1a);border:1px solid var(--border,#333)">
    <h2 style="font-size:1.1rem;margin:0 0 10px">Firefox</h2>
    <ol style="margin:0;padding-left:1.2em;line-height:1.55">
      <li>Распакуй ZIP.</li>
      <li>Открой <code>about:debugging#/runtime/this-firefox</code>.</li>
      <li>«Загрузить временное дополнение» → файл <code>manifest.json</code>.</li>
    </ol>
  </div>

  <div class="card" style="padding:16px;border-radius:12px;background:var(--card,#1a1a1a);border:1px solid var(--border,#333)">
    <h2 style="font-size:1.1rem;margin:0 0 10px">Если платформа недоступна</h2>
    <p style="margin:0;color:var(--text-dim,#aaa);line-height:1.5">
      В окне расширения откроется страница-заглушка «Платформа временно недоступна». Зайди позже или проверь интернет.
      Адрес можно сменить: ПКМ по иконке расширения → Параметры.
    </p>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
