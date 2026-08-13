<?php
/**
 * Лендинг StreamLive партнёры (как brands_welcome.php)
 */
$siteName = defined('SITE_NAME') ? SITE_NAME : 'StreamLive';
$e = static function ($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};
if (is_file(__DIR__ . '/includes/functions.php')) {
  require_once __DIR__ . '/includes/functions.php';
  if (defined('SITE_NAME')) $siteName = SITE_NAME;
}
if (is_file(__DIR__ . '/includes/partners.php')) {
  require_once __DIR__ . '/includes/partners.php';
  if (function_exists('partners_remember_ref_from_request')) {
    try { partners_remember_ref_from_request(); } catch (Throwable $ex) {}
  }
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($siteName) ?> партнёры — промокоды и рефералы</title>
<meta name="description" content="StreamLive партнёры: свой промокод навсегда, статистика активаций, реферальная ссылка. Активация даёт платную подписку и до 50 бренд-аккаунтов.">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #0b0e14; --fg: #f1f5f9; --muted: #94a3b8; --card: #151a24;
  --accent: #a78bfa; --radius: 14px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--fg);line-height:1.55;min-height:100vh}
.wrap{max-width:720px;margin:0 auto;padding:48px 20px}
h1{font-family:Unbounded,sans-serif;font-size:clamp(1.6rem,4vw,2.2rem);margin-bottom:12px}
.lead{color:var(--muted);margin-bottom:28px;font-size:1.05rem}
.grid{display:grid;gap:12px;margin:24px 0}
.card{background:var(--card);border-radius:var(--radius);padding:16px 18px;border:1px solid rgba(255,255,255,.06)}
.card strong{display:block;margin-bottom:4px}
.card span{color:var(--muted);font-size:.92rem}
.cta{display:inline-block;margin:8px 8px 0 0;padding:14px 18px;border-radius:12px;font-weight:800;text-decoration:none}
.cta-main{background:var(--accent);color:#0b0e14}
.cta-ghost{background:#1e293b;color:#fff}
.note{margin-top:28px;font-size:13px;color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
  <h1>StreamLive партнёры</h1>
  <p class="lead">Создайте свой промокод один раз — навсегда. Делитесь им и реферальной ссылкой, смотрите активации. Код нельзя переименовать; администрация его не отклоняет.</p>
  <div class="grid">
    <div class="card"><strong>Свой код</strong><span>Один промокод на аккаунт. Переименовать нельзя</span></div>
    <div class="card"><strong>Активация</strong><span>Только с личного аккаунта (не с бренда), один раз на пользователя</span></div>
    <div class="card"><strong>Бонус активировавшему</strong><span><?= (int)(defined('PARTNER_ACCESS_DAYS')?PARTNER_ACCESS_DAYS:30) ?> дней подписки: до 50 бренд-аккаунтов</span></div>
    <div class="card"><strong>Статистика</strong><span>Активации, активные подписки, рефералы по ссылке</span></div>
  </div>
  <a class="cta cta-main" href="/partners/">Кабинет партнёра</a>
  <a class="cta cta-ghost" href="/partners/?tab=activate">Активировать код</a>
  <a class="cta cta-ghost" href="/brands_welcome.php">Бренды</a>
  <a class="cta cta-ghost" href="/">На главную</a>
  <p class="note">StreamLive · партнёрская программа · промокод навсегда</p>
</div>
</body>
</html>
