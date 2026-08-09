<?php
/**
 * После переноса БД/домена: видео «не открываются», 2FA, название сайта.
 * Только admin. Запусти 1 раз, потом удали файл с сервера.
 */
require_once __DIR__ . '/_layout_start.php';

$report = [];
$do = (string)($_POST['do'] ?? '');

function mr_count(string $sql): int {
  try { return (int)db()->query($sql)->fetchColumn(); } catch (Throwable $e) { return -1; }
}

if ($do === 'fix_videos') {
  $n = 0;
  try {
    // Публичные ролики, которые после миграции зависли в странных статусах
    $st = db()->prepare(
      "UPDATE videos SET status = 'published'
       WHERE status IN ('draft','failed','pending','processing','')
         OR status IS NULL"
    );
    $st->execute();
    $n = $st->rowCount();
  } catch (Throwable $e) {
    $report[] = 'videos status: ' . $e->getMessage();
  }
  try {
    $st = db()->prepare(
      "UPDATE videos SET mod_status = 'approved'
       WHERE mod_status IN ('pending','rejected') OR mod_status IS NULL OR mod_status = ''"
    );
    $st->execute();
    $report[] = 'videos mod_status updated: ' . $st->rowCount();
  } catch (Throwable $e) {
    $report[] = 'mod_status: ' . $e->getMessage() . ' (колонки может не быть — ок)';
  }
  $report[] = 'videos → published: ' . $n;
}

if ($do === 'fix_videos_safe') {
  // Только те, у которых есть slug и channel
  try {
    $st = db()->exec(
      "UPDATE videos v
       INNER JOIN channels c ON c.id = v.channel_id
       SET v.status = 'published'
       WHERE (v.status IS NULL OR v.status IN ('','draft','failed','pending','processing'))
         AND v.slug IS NOT NULL AND v.slug != ''"
    );
    $report[] = 'safe published (с каналом): ok';
  } catch (Throwable $e) {
    $report[] = 'safe: ' . $e->getMessage();
  }
  try {
    db()->exec(
      "UPDATE videos SET mod_status = 'approved'
       WHERE mod_status IS NULL OR mod_status IN ('','pending','rejected')"
    );
    $report[] = 'mod_status approved';
  } catch (Throwable $e) {
    $report[] = 'mod: ' . $e->getMessage();
  }
}

if ($do === 'reset_2fa_all') {
  try {
    $st = db()->prepare('UPDATE users SET totp_enabled = 0, totp_secret = NULL');
    $st->execute();
    $report[] = '2FA сброшен у всех: ' . $st->rowCount() . ' строк';
  } catch (Throwable $e) {
    $report[] = '2FA: ' . $e->getMessage();
  }
  try {
    if (function_exists('set_setting')) {
      set_setting('force_2fa_enabled', '0');
      $report[] = 'force_2fa_enabled = 0';
    }
  } catch (Throwable $e) {}
}

if ($do === 'disable_force_2fa') {
  try {
    if (function_exists('set_setting')) {
      set_setting('force_2fa_enabled', '0');
      $report[] = 'Обязательный 2FA выключен — можно войти без кода, потом настроить заново';
    }
  } catch (Throwable $e) {
    $report[] = $e->getMessage();
  }
}

// Stats
$stats = [
  'videos total' => mr_count('SELECT COUNT(*) FROM videos'),
  'videos published' => mr_count("SELECT COUNT(*) FROM videos WHERE status='published'"),
  'videos draft/failed/other' => mr_count("SELECT COUNT(*) FROM videos WHERE status IS NULL OR status NOT IN ('published')"),
  'users 2fa on' => mr_count('SELECT COUNT(*) FROM users WHERE totp_enabled=1'),
];
try {
  $stats['videos mod pending'] = mr_count("SELECT COUNT(*) FROM videos WHERE mod_status='pending'");
} catch (Throwable $e) {
  $stats['videos mod pending'] = 'n/a';
}
?>
<h2>Ремонт после переноса</h2>
<p style="opacity:.8">Домен сменился (Infinity → platforma.blyz.ru). Видео могли остаться не в <code>published</code>, 2FA у всех нужно настроить заново или сбросить.</p>

<div class="card" style="padding:14px;margin:12px 0">
  <b>Статистика</b>
  <ul>
    <?php foreach ($stats as $k => $v): ?>
      <li><?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>: <b><?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?></b></li>
    <?php endforeach; ?>
  </ul>
  <p>SITE_NAME: <code><?= htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : '?', ENT_QUOTES, 'UTF-8') ?></code></p>
  <p>SITE_URL: <code><?= htmlspecialchars(defined('SITE_URL') ? SITE_URL : '?', ENT_QUOTES, 'UTF-8') ?></code></p>
</div>

<?php if ($report): ?>
  <div class="card" style="padding:14px;margin:12px 0;background:rgba(34,211,238,.1)">
    <?php foreach ($report as $line): ?>
      <div><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" style="margin:10px 0" onsubmit="return confirm('Пометить проблемные видео как published?');">
  <?= function_exists('csrf_field') ? csrf_field() : '' ?>
  <button class="btn btn-primary" name="do" value="fix_videos_safe" type="submit">1. Починить видео (безопасно)</button>
</form>
<form method="post" style="margin:10px 0" onsubmit="return confirm('Сбросить 2FA у ВСЕХ пользователей?');">
  <?= function_exists('csrf_field') ? csrf_field() : '' ?>
  <button class="btn btn-danger" name="do" value="reset_2fa_all" type="submit">2. Сбросить 2FA всем</button>
</form>
<form method="post" style="margin:10px 0">
  <?= function_exists('csrf_field') ? csrf_field() : '' ?>
  <button class="btn" name="do" value="disable_force_2fa" type="submit">3. Выключить обязательный 2FA</button>
</form>

<div class="card" style="padding:14px;margin:16px 0;opacity:.9;font-size:14px">
  <b>Конфиг</b>
  <p>В <code>config.php</code> на новом хосте должно быть примерно:</p>
  <pre style="white-space:pre-wrap;font-size:12px">define('SITE_NAME', 'PL Video');  // или Platforma — как хочешь в шапке
define('SITE_URL', 'https://platforma.blyz.ru');</pre>
  <p>Если в шапке «DOM TV» — правится <b>только config.php</b> (или settings в БД, если имя берётся оттуда). После правки обнови страницу с Ctrl+F5.</p>
  <p>2FA: секрет в БД привязан к аккаунту, не к домену. После смены домена приложение может показывать старое имя — коды обычно те же. Если не пускает — жми «Сбросить 2FA всем» и пусть заново привяжут.</p>
  <p style="color:#f87171">После использования <b>удали</b> этот файл: <code>admin/migrate_repair.php</code></p>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
