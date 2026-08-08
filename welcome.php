<?php
/**
 * StreamLive landing — layout 1:1 как embedstreamru.lovable.app
 */
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
elseif (is_file(__DIR__ . '/includes/config.php')) require_once __DIR__ . '/includes/config.php';

$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$siteUrl  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$home     = $siteUrl !== '' ? $siteUrl : '';
$catalog  = $home . '/videos';
$create   = $home . '/channel_manage.php';
$forum    = $home . '/forum';
$login    = $home . '/auth/login.php';
$register = $home . '/auth/register.php';
$studio   = $home . '/platforma/studio/';
$e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($siteName) ?> — платформа стримов, ТВ, видео и форума</title>
<meta name="description" content="StreamLive — онлайн-каналы ТВ и радио, каталог видео, импорт с YouTube, VK, Instagram, Dropbox, форум, модерация, студия.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
<style>
/* Токены как на embedstreamru.lovable.app */
:root {
  --background: oklch(16% .012 250);
  --foreground: oklch(97% .005 250);
  --card: oklch(20% .014 250);
  --primary: oklch(82% .17 176);
  --primary-foreground: oklch(17% .03 190);
  --primary-glow: oklch(90% .15 190);
  --muted: oklch(24% .014 250);
  --muted-foreground: oklch(68% .014 250);
  --border: oklch(30% .014 250);
  --radius: 0.75rem;
  --gradient-hero:
    radial-gradient(120% 90% at 15% -10%, color-mix(in oklab, var(--primary) 26%, transparent), transparent 60%),
    radial-gradient(90% 70% at 100% 0%, color-mix(in oklab, var(--primary-glow) 14%, transparent), transparent 55%);
  --shadow-soft: 0 24px 60px -30px color-mix(in oklab, var(--primary) 45%, transparent);
  --font-display: "Unbounded", "Inter", system-ui, sans-serif;
  --font-sans: "Inter", system-ui, sans-serif;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: var(--font-sans);
  background: var(--background);
  color: var(--foreground);
  min-height: 100vh;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}
a { color: inherit; text-decoration: none; }
.mx-auto { margin-left: auto; margin-right: auto; }
.max-w-6xl { max-width: 72rem; }
.px-5 { padding-left: 1.25rem; padding-right: 1.25rem; }

/* disclaimer — тонкая полоска в духе сайта */
.sl-disclaimer {
  border-bottom: 1px solid color-mix(in oklab, var(--border) 80%, transparent);
  background: color-mix(in oklab, var(--card) 70%, transparent);
  color: var(--muted-foreground);
  font-size: 12px;
  line-height: 1.45;
  text-align: center;
  padding: 8px 16px;
}
.sl-disclaimer strong { color: var(--foreground); font-weight: 600; }

/* header — как EmbedStream: лого слева, ссылка справа */
header.es-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 1.5rem;
  padding-bottom: 1.5rem;
}
.logo {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 1.125rem;
  letter-spacing: -0.02em;
}
.logo .accent { color: var(--primary); }
.header-link {
  font-size: 0.875rem;
  color: var(--muted-foreground);
  transition: color .15s;
}
.header-link:hover { color: var(--primary); }

/* hero */
.es-hero {
  position: relative;
  overflow: hidden;
  background-image: var(--gradient-hero);
}
.es-grid {
  pointer-events: none;
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(to right, color-mix(in oklab, var(--border) 60%, transparent) 1px, transparent 1px),
    linear-gradient(to bottom, color-mix(in oklab, var(--border) 60%, transparent) 1px, transparent 1px);
  background-size: 56px 56px;
  -webkit-mask-image: radial-gradient(80% 60% at 50% 0%, #000, transparent 75%);
  mask-image: radial-gradient(80% 60% at 50% 0%, #000, transparent 75%);
}
.es-hero-inner {
  position: relative;
  padding: 3.5rem 0 5rem;
}
.es-badge {
  display: inline-block;
  border-radius: 9999px;
  border: 1px solid var(--border);
  background: color-mix(in oklab, var(--card) 60%, transparent);
  padding: 0.25rem 0.75rem;
  font-size: 0.75rem;
  color: var(--muted-foreground);
}
.es-hero h1 {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: clamp(2.25rem, 6vw, 3.75rem);
  line-height: 1.05;
  letter-spacing: -0.03em;
  margin-top: 1.5rem;
  max-width: 20ch;
}

.es-type {
  color: var(--primary);
}

.es-caret {
  display: inline-block;
  width: 2px;
  height: 0.9em;
  margin-left: 2px;
  vertical-align: -2px;
  background: var(--primary);
  animation: es-caret 1s step-end infinite;
}
@keyframes es-caret {
  0%, 100% { opacity: 1; }
  50% { opacity: 0; }
}
.es-lead {
  margin-top: 1.25rem;
  max-width: 36rem;
  font-size: 1rem;
  color: var(--muted-foreground);
}
.es-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 1.75rem;
}
.btn-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: calc(var(--radius) + 4px);
  padding: 0.75rem 1.35rem;
  font-size: 0.9rem;
  font-weight: 600;
  background: var(--primary);
  color: var(--primary-foreground);
  box-shadow: var(--shadow-soft);
  transition: transform .15s, filter .15s;
}
.btn-primary:hover { filter: brightness(1.05); transform: translateY(-1px); }
.btn-ghost {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: calc(var(--radius) + 4px);
  padding: 0.75rem 1.25rem;
  font-size: 0.9rem;
  font-weight: 500;
  border: 1px solid var(--border);
  color: var(--foreground);
  background: transparent;
  transition: background .15s;
}
.btn-ghost:hover { background: color-mix(in oklab, var(--card) 80%, transparent); }

/* marquee */
.es-marquee-wrap {
  overflow: hidden;
  border-top: 1px solid color-mix(in oklab, var(--border) 50%, transparent);
  border-bottom: 1px solid color-mix(in oklab, var(--border) 50%, transparent);
  background: color-mix(in oklab, var(--card) 40%, transparent);
  mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
  -webkit-mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent);
}
.es-marquee {
  display: flex;
  gap: 0.5rem;
  width: max-content;
  padding: 0.85rem 0;
  animation: es-marquee 32s linear infinite;
}
.es-marquee span {
  flex-shrink: 0;
  font-size: 0.75rem;
  padding: 0.35rem 0.7rem;
  border-radius: 0.5rem;
  border: 1px solid var(--border);
  background: var(--card);
  color: var(--muted-foreground);
  white-space: nowrap;
}
@keyframes es-marquee {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}

/* sections */
section {
  padding: 4rem 0;
}
section h2 {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: clamp(1.5rem, 3vw, 1.875rem);
  letter-spacing: -0.02em;
  margin-bottom: 0.5rem;
}
.section-lead {
  color: var(--muted-foreground);
  max-width: 36rem;
  margin-bottom: 2rem;
  font-size: 0.95rem;
}

.es-rise {
  animation: es-rise .7s cubic-bezier(.2, .7, .2, 1) both;
}
@keyframes es-rise {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}

.grid-3 {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
}
.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 4px);
  padding: 1.35rem 1.4rem;
}
.card .num {
  font-family: var(--font-display);
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--primary);
  margin-bottom: 0.5rem;
}
.card h3 {
  font-size: 1.05rem;
  font-weight: 600;
  margin-bottom: 0.35rem;
}
.card p {
  font-size: 0.875rem;
  color: var(--muted-foreground);
}

.feature-row {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  margin-top: 1rem;
}
.feature-card {
  border: 1px solid var(--border);
  border-radius: calc(var(--radius) + 4px);
  background: color-mix(in oklab, var(--card) 80%, transparent);
  padding: 1.25rem 1.35rem;
}
.feature-card h3 { font-size: 1rem; margin-bottom: 0.35rem; }
.feature-card p { font-size: 0.875rem; color: var(--muted-foreground); }

/* FAQ */
.faq details {
  border: 1px solid var(--border);
  background: var(--card);
  border-radius: var(--radius);
  padding: 0.9rem 1.1rem;
  margin-bottom: 0.5rem;
}
.faq summary {
  cursor: pointer;
  font-weight: 600;
  font-size: 0.9375rem;
  list-style: none;
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: center;
}
.faq summary::-webkit-details-marker { display: none; }
.faq summary::after {
  content: '+';
  color: var(--muted-foreground);
  font-weight: 400;
  font-size: 1.1rem;
}
.faq details[open] summary::after { content: '−'; }
.faq details p {
  margin-top: 0.65rem;
  font-size: 0.875rem;
  color: var(--muted-foreground);
}

.docs-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}
.doc-card a {
  display: block;
  height: 100%;
  border: 1px solid var(--border);
  background: var(--card);
  border-radius: calc(var(--radius) + 2px);
  padding: 1rem 1.1rem;
  transition: border-color .15s, box-shadow .15s;
}
.doc-card a:hover {
  border-color: color-mix(in oklab, var(--primary) 45%, var(--border));
  box-shadow: var(--shadow-soft);
}
.doc-card h3 { font-size: 0.9rem; margin-bottom: 0.25rem; }
.doc-card p { font-size: 0.75rem; color: var(--muted-foreground); }

.cta-band {
  margin: 0 0 3rem;
  padding: 2.25rem 1.5rem;
  text-align: center;
  border-radius: 1.25rem;
  border: 1px solid var(--border);
  background: var(--gradient-hero), var(--card);
}
.cta-band h2 { margin-bottom: 0.5rem; }
.cta-band p { color: var(--muted-foreground); margin-bottom: 1.25rem; font-size: 0.95rem; }
.cta-actions { display: flex; flex-wrap: wrap; gap: 0.65rem; justify-content: center; }

footer {
  border-top: 1px solid color-mix(in oklab, var(--border) 60%, transparent);
  padding: 1.5rem 0 2rem;
  text-align: center;
  font-size: 0.75rem;
  color: var(--muted-foreground);
}
footer a {
  color: color-mix(in oklab, var(--foreground) 80%, transparent);
  text-decoration: underline;
  text-underline-offset: 3px;
}

@media (max-width: 640px) {
  .es-hero-inner { padding-top: 2rem; padding-bottom: 3rem; }
  .es-actions .btn-primary,
  .es-actions .btn-ghost { width: 100%; }
}
</style>
</head>
<body>

<div class="sl-disclaimer" role="note">
  <strong>Важно:</strong> мы не несём ответственности за действия пользователей.
  Мы отвечаем за публикацию контента: материалы проходят модерацию, при нарушении правил публикация может быть отклонена или снята.
</div>

<header class="es-header mx-auto max-w-6xl px-5">
  <a class="logo" href="<?= $e($home ?: '#') ?>"><?= $e($siteName) ?><span class="accent">.</span></a>
  <a class="header-link" href="<?= $e($home ?: '/') ?>" target="_blank" rel="noopener">открыть площадку ↗</a>
</header>

<section class="es-hero">
  <div class="es-grid" aria-hidden="true"></div>
  <div class="es-hero-inner mx-auto max-w-6xl px-5">
    <span class="es-badge es-rise">ТВ · радио · видео · форум · студия · импорт</span>
    <h1 class="es-rise">
      Своя платформа<br>
      стримов и <span id="es-type" class="es-type"></span><span class="es-caret" aria-hidden="true"></span>
    </h1>
    <p class="es-lead es-rise">
      <?= $e($siteName) ?> — единая площадка: онлайн-каналы ТВ и радио, каталог видео,
      импорт с YouTube, VK, Instagram, Rutube, Dropbox и других источников, форум с модерацией,
      студия публикации и встраиваемые плееры.
    </p>
    <div class="es-actions es-rise">
      <a class="btn-primary" href="<?= $e($catalog) ?>">Открыть каталог</a>
      <a class="btn-ghost" href="<?= $e($create) ?>">Создать канал</a>
      <a class="btn-ghost" href="<?= $e($register) ?>">Регистрация</a>
    </div>
  </div>
</section>

<div class="es-marquee-wrap" aria-hidden="true">
  <div class="es-marquee">
    <?php
    $plats = ['YouTube','VK','Rutube','Instagram','TikTok','Dropbox','Vimeo','Одноклассники','Twitch','Telegram','Google Drive','Яндекс Диск','Dailymotion','SoundCloud','MP4 / HLS'];
    // два круга для бесшовной ленты
    for ($loop = 0; $loop < 2; $loop++):
      foreach ($plats as $p): ?>
        <span><?= $e($p) ?></span>
    <?php endforeach; endfor; ?>
  </div>
</div>

<section id="how">
  <div class="mx-auto max-w-6xl px-5">
    <h2>Как это работает</h2>
    <p class="section-lead">От аккаунта до эфира и каталога — без лишней возни.</p>
    <div class="grid-3">
      <div class="card">
        <div class="num">01</div>
        <h3>Регистрируетесь</h3>
        <p>Аккаунт на площадке или вход через соцсети, если они включены в админке.</p>
      </div>
      <div class="card">
        <div class="num">02</div>
        <h3>Создаёте канал или видео</h3>
        <p>ТВ/радио с источником эфира либо публикация и импорт роликов с обложкой, названием и тегами.</p>
      </div>
      <div class="card">
        <div class="num">03</div>
        <h3>Модерация и эфир</h3>
        <p>После одобрения контент в каталоге и в эфире. Зрители смотрят, обсуждают на форуме, добавляют в избранное.</p>
      </div>
    </div>
    <div class="feature-row">
      <div class="feature-card">
        <h3>Импорт с платформ</h3>
        <p>Ссылка с YouTube, VK, Instagram, Dropbox и др. — подтягиваем метаданные и отдаём плеер на площадке.</p>
      </div>
      <div class="feature-card">
        <h3>Платный / закрытый доступ</h3>
        <p>Канал можно закрыть промокодом. Встраивание платного контента отключено, чтобы не обходили доступ.</p>
      </div>
      <div class="feature-card">
        <h3>Модерация контента</h3>
        <p>Видео, темы и посты форума в очереди. Pending видят автор и staff — не вся лента сразу.</p>
      </div>
    </div>
  </div>
</section>

<section id="about">
  <div class="mx-auto max-w-6xl px-5">
    <h2>О платформе</h2>
    <p class="section-lead">
      <?= $e($siteName) ?> — не только embed-плеер, а полноценный сервис: вещание, VOD, сообщество и студия в одном стиле.
    </p>
    <div class="grid-3">
      <div class="card">
        <div class="num">ТВ / радио</div>
        <h3>Онлайн-каналы</h3>
        <p>Источники, расписание, логотип, SEO, пауза эфира, платный доступ.</p>
      </div>
      <div class="card">
        <div class="num">VOD</div>
        <h3>Каталог видео</h3>
        <p>Публикации, импорт, премьеры, рекомендации, категории.</p>
      </div>
      <div class="card">
        <div class="num">Сообщество</div>
        <h3>Форум</h3>
        <p>Темы, ответы, жалобы, автозакрытие неактивных обсуждений.</p>
      </div>
    </div>
  </div>
</section>

<section id="faq" class="faq">
  <div class="mx-auto max-w-6xl px-5">
    <h2>FAQ</h2>
    <p class="section-lead">Коротко по делу.</p>
    <details>
      <summary>Как создать канал?</summary>
      <p>Войдите → «Создать канал». Укажите название, источник, логотип. После модерации канал появится в каталоге.</p>
    </details>
    <details>
      <summary>Какие ссылки импортируются?</summary>
      <p>YouTube, VK, Rutube, Instagram, TikTok, Dropbox, Twitch, прямые MP4/HLS и другие источники, которые поддерживает парсер площадки.</p>
    </details>
    <details>
      <summary>Почему пост или видео «на модерации»?</summary>
      <p>Новые материалы проходят проверку. Пока pending — их видят автор и модераторы; остальным — после одобрения.</p>
    </details>
    <details>
      <summary>Можно встроить плеер на свой сайт?</summary>
      <p>Да, через embed канала или видео. У платных/закрытых каналов встраивание запрещено.</p>
    </details>
    <details>
      <summary>Кто отвечает за контент?</summary>
      <p>Пользователи — за свои действия и материалы. Администрация — за правила публикации: модерация, отклонение и снятие при нарушениях.</p>
    </details>
  </div>
</section>

<section id="docs">
  <div class="mx-auto max-w-6xl px-5">
    <h2>Документация</h2>
    <p class="section-lead">Разделы площадки — с этой же страницы.</p>
    <div class="docs-grid">
      <div class="doc-card"><a href="<?= $e($create) ?>"><h3>Каналы</h3><p>ТВ/радио, источники, платный доступ</p></a></div>
      <div class="doc-card"><a href="<?= $e($catalog) ?>"><h3>Видео</h3><p>Каталог, импорт, премьеры</p></a></div>
      <div class="doc-card"><a href="<?= $e($forum) ?>"><h3>Форум</h3><p>Темы, ответы, модерация</p></a></div>
      <div class="doc-card"><a href="<?= $e($studio) ?>"><h3>Студия</h3><p>Публикация и управление</p></a></div>
      <div class="doc-card"><a href="<?= $e($login) ?>"><h3>Вход</h3><p>Аккаунт и соц. авторизация</p></a></div>
      <div class="doc-card"><a href="#faq"><h3>Правила</h3><p>Модерация и ответственность</p></a></div>
    </div>
  </div>
</section>

<div class="mx-auto max-w-6xl px-5">
  <div class="cta-band">
    <h2 style="font-family:var(--font-display);font-weight:700;letter-spacing:-.02em">Готовы к эфиру?</h2>
    <p>Каталог, свой канал или регистрация — с одного экрана.</p>
    <div class="cta-actions">
      <a class="btn-primary" href="<?= $e($register) ?>">Регистрация</a>
      <a class="btn-ghost" href="<?= $e($catalog) ?>">Каталог</a>
      <a class="btn-ghost" href="<?= $e($home ?: '/') ?>">На площадку ↗</a>
    </div>
  </div>
</div>

<footer>
  <div class="mx-auto max-w-6xl px-5">
    <p>© <?= date('Y') ?> <?= $e($siteName) ?>. Контент публикуют пользователи; администрация модерирует публикации.</p>
    <p style="margin-top:0.5rem">
      <a href="<?= $e($home ?: '/') ?>">Площадка</a> ·
      <a href="#faq">FAQ</a> ·
      <a href="#docs">Документация</a>
    </p>
  </div>
</footer>


<script>
(function () {
  var phrases = [
    'видео',
    'эфиры',
    'каналы',
    'импорт',
    'форум',
    'студию',
    'плееры'
  ];
  var el = document.getElementById('es-type');
  if (!el) return;
  var pi = 0, ci = 0, del = false;
  var typeMs = 70, delMs = 45, holdMs = 1600, gapMs = 400;

  function tick() {
    var full = phrases[pi];
    if (!del) {
      ci++;
      el.textContent = full.slice(0, ci);
      if (ci >= full.length) {
        del = true;
        setTimeout(tick, holdMs);
        return;
      }
      setTimeout(tick, typeMs);
    } else {
      ci--;
      el.textContent = full.slice(0, Math.max(0, ci));
      if (ci <= 0) {
        del = false;
        pi = (pi + 1) % phrases.length;
        setTimeout(tick, gapMs);
        return;
      }
      setTimeout(tick, delMs);
    }
  }
  tick();
})();
</script>

</body>
</html>
