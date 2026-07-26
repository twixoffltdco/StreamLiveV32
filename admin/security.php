<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
// Самолечение: колонки totp_secret/totp_enabled могли не появиться, если миграция
// sql/migrations/003_add_features.sql не была накатана руками на проде. Без них
// проверка 2FA просто не может работать корректно. Проверяем и добавляем сами —
// один раз, безопасно (IF NOT EXISTS-подобная проверка через information_schema).
function ensure_totp_columns(): void {
  $pdo = db();
  $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('totp_secret','totp_enabled')");
  $stmt->execute([$dbName]);
  $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('totp_secret', $existing, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL');
  }
  if (!in_array('totp_enabled', $existing, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0');
  }
}
try { ensure_totp_columns(); } catch (\Throwable $e) { /* если БД не MySQL или нет прав — просто не мешаем странице открыться */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if (isset($_POST['enabled'])) {
    set_setting('force_2fa_enabled', $_POST['enabled'] === '1' ? '1' : '0');
    flash_set('success', $_POST['enabled'] === '1' ? '2FA включена для всех' : '2FA выключена для всех');
  } elseif (isset($_POST['geo_enabled'])) {
    set_setting('geo_restrict_enabled', $_POST['geo_enabled'] === '1' ? '1' : '0');
    flash_set('success', $_POST['geo_enabled'] === '1' ? 'Гео-ограничение включено (доступ только из СНГ)' : 'Гео-ограничение выключено (доступ из любой страны)');
  } elseif (isset($_POST['ddos_mode'])) {
    set_setting('ddos_under_attack_mode', $_POST['ddos_mode'] === '1' ? '1' : '0');
    flash_set('success', $_POST['ddos_mode'] === '1' ? 'Режим "под атакой" включён — все гости проходят JS-проверку браузера' : 'Режим "под атакой" выключен');
  } elseif (isset($_POST['save_moderation_limits'])) {
    set_setting('no_self_moderation_enabled', !empty($_POST['no_self_moderation_enabled']) ? '1' : '0');
    set_setting('moderation_cooldown_hours', (string)max(0, (int)($_POST['moderation_cooldown_hours'] ?? 24)));
    set_setting('role_change_cooldown_hours', (string)max(0, (int)($_POST['role_change_cooldown_hours'] ?? 100)));
    flash_set('success', 'Лимиты модерации сохранены');
  }
  redirect('/admin/security.php');
}
$enabled = get_setting('force_2fa_enabled', '0') === '1';
$geoEnabled = get_setting('geo_restrict_enabled', '0') === '1';
$ddosMode = get_setting('ddos_under_attack_mode', '0') === '1';
$noSelfModeration = get_setting('no_self_moderation_enabled', '1') === '1';
$moderationCooldownHours = (int)get_setting('moderation_cooldown_hours', '24');
$roleChangeCooldownHours = (int)get_setting('role_change_cooldown_hours', '100');
?>
<h2>Безопасность — 2FA</h2>
<div class="form-card form-wide">
  <p style="color:var(--text-dim);font-size:13px;max-width:560px">
    Сейчас 2FA <b style="color:<?= $enabled ? 'var(--ok)' : 'var(--danger)' ?>"><?= $enabled ? 'включена' : 'выключена' ?></b> для всех пользователей.
    Пока выключена — вход работает как обычно, по логину и паролю, без кода из приложения.
    Прежде чем включать на проде, зайди под своим аккаунтом с ПК и с телефона и убедись, что код принимается.
  </p>
  <form method="POST" style="margin-top:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
    <button class="btn <?= $enabled ? 'btn-danger' : 'btn-primary' ?>" type="submit">
      <?= $enabled ? 'Выключить 2FA для всех' : 'Включить 2FA для всех' ?>
    </button>
  </form>
</div>

<h2 style="margin-top:30px">Гео-ограничение (только СНГ)</h2>
<div class="form-card form-wide">
  <p style="color:var(--text-dim);font-size:13px;max-width:560px">
    Сейчас доступ к сайту <b style="color:<?= $geoEnabled ? 'var(--ok)' : 'var(--danger)' ?>"><?= $geoEnabled ? 'ограничен странами СНГ' : 'открыт из любой страны' ?></b>.
    Определение страны — по IP через бесплатный внешний сервис, с кэшем на 30 дней (чтобы не дёргать его на каждый визит).
    Если сервис определения недоступен — посетителя пропускаем (чтобы не заблокировать всех по ошибке).
    Разрешённые страны: <?= implode(', ', geo_whitelist_countries()) ?>. Админку это ограничение не затрагивает никогда.
  </p>
  <form method="POST" style="margin-top:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="geo_enabled" value="<?= $geoEnabled ? '0' : '1' ?>">
    <button class="btn <?= $geoEnabled ? 'btn-danger' : 'btn-primary' ?>" type="submit">
      <?= $geoEnabled ? 'Выключить гео-ограничение' : 'Включить гео-ограничение (только СНГ)' ?>
    </button>
  </form>
</div>

<h2 style="margin-top:30px">Антидудос — режим «под атакой»</h2>
<div class="form-card form-wide">
  <p style="color:var(--text-dim);font-size:13px;max-width:560px">
    Сейчас режим «под атакой» <b style="color:<?= $ddosMode ? 'var(--ok)' : 'var(--danger)' ?>"><?= $ddosMode ? 'включён' : 'выключен' ?></b>.
    Когда включён — <b>каждый гость</b> проходит лёгкую проверку браузера (JS высчитывает hash, доли секунды у настоящего
    браузера, но отсеивает большинство простых флуд-скриптов без JS-движка) прежде чем попасть на сайт.
    Включай вручную на время реальной атаки — постоянно держать включённым не нужно, обычный антибот
    (<code>includes/antibot.php</code>) и защита от перегрузки БД (<code>includes/ddos_shield.php</code>) работают всегда сами,
    без этого переключателя.
  </p>
  <form method="POST" style="margin-top:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="ddos_mode" value="<?= $ddosMode ? '0' : '1' ?>">
    <button class="btn <?= $ddosMode ? 'btn-danger' : 'btn-primary' ?>" type="submit">
      <?= $ddosMode ? 'Выключить режим "под атакой"' : 'Включить режим "под атакой"' ?>
    </button>
  </form>
</div>

<h2 style="margin-top:30px">Живая диагностика — почему мог быть 503</h2>
<div class="form-card form-wide">
  <?php
  // Диагностика слоя 1 (файловый circuit breaker, includes/ddos_shield.php) — считаем
  // сколько .lock-файлов сейчас реально лежит на диске, как это делает сам ddos_concurrency_guard().
  $__lockCount = 0;
  if (is_dir(DDOS_LOCK_DIR)) { $__lockCount = count(glob(DDOS_LOCK_DIR . '/*.lock') ?: []); }

  // Диагностика слоя 2 (общесайтовый автотриггер ВНУТРИ antibot.php — НЕЗАВИСИМ от тумблера
  // выше! Включается сам при всплеске трафика, см. antibot_register_global_request()).
  $__autoAttackUntil = null;
  try {
    $__row = db()->query('SELECT attack_until FROM antibot_global_window WHERE id = 1')->fetch();
    $__autoAttackUntil = $__row['attack_until'] ?? null;
  } catch (\Throwable $e) { }
  $__autoAttackActive = $__autoAttackUntil && strtotime($__autoAttackUntil) > time();
  ?>
  <p style="font-size:13px;color:var(--text-dim);max-width:600px">
    503 может прийти от ТРЁХ независимых мест — тумблер выше выключает только одно из них.
    Если гости (включая ИИ-краулеров) всё ещё видят 503 при выключенном тумблере — смотри сюда:
  </p>
  <table class="admin-table" style="width:100%;max-width:640px">
    <tr>
      <td>Слой 1: "сайт перегружен" (файловый лимит одновременных запросов)</td>
      <td><b style="color:<?= $__lockCount >= DDOS_MAX_CONCURRENT ? 'var(--danger)' : 'var(--ok)' ?>"><?= $__lockCount ?> / <?= DDOS_MAX_CONCURRENT ?></b></td>
    </tr>
    <tr>
      <td>Слой 2: тумблер выше (ручной режим "под атакой")</td>
      <td><b style="color:<?= $ddosMode ? 'var(--danger)' : 'var(--ok)' ?>"><?= $ddosMode ? 'включён' : 'выключен' ?></b></td>
    </tr>
    <tr>
      <td>Слой 3: автотриггер общесайтового JS-челленджа <span style="color:var(--text-dim);font-size:11px">(independent, includes/antibot.php)</span></td>
      <td><b style="color:<?= $__autoAttackActive ? 'var(--danger)' : 'var(--ok)' ?>"><?= $__autoAttackActive ? ('включён до ' . e($__autoAttackUntil)) : 'выключен' ?></b></td>
    </tr>
  </table>
  <p style="font-size:12px;color:var(--text-dim);margin-top:10px">
    Известные краулеры/ИИ-агенты (GPTBot, ChatGPT-User, PerplexityBot, ClaudeBot и т.д.) теперь
    пропускают Слои 2 и 3 всегда — им никогда не показывается JS-проверка. DeepSeek не публикует
    свой User-Agent, поэтому под него никакое точечное исключение не сработает — единственная
    защита для него (и для любого другого нераспознанного автоматического клиента) — держать
    оба режима выключенными, кроме случаев реальной атаки.
  </p>
</div>
<h2 style="margin-top:30px">Лимиты модерации</h2>
<div class="form-card form-wide">
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="save_moderation_limits" value="1">
    <label style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" name="no_self_moderation_enabled" value="1" <?= $noSelfModeration ? 'checked' : '' ?>>
      Запретить модератору одобрять/отклонять свой же канал или видео
    </label>
    <label style="margin-top:10px">Кулдаун модерации на модератора (часов на 1 действие с каналами/видео каждого типа отдельно)</label>
    <input type="number" name="moderation_cooldown_hours" value="<?= (int)$moderationCooldownHours ?>" min="0" style="max-width:120px">
    <label style="margin-top:10px">Кулдаун смены роли модератора (часов между сменами роли ОДНОГО пользователя)</label>
    <input type="number" name="role_change_cooldown_hours" value="<?= (int)$roleChangeCooldownHours ?>" min="0" style="max-width:120px">
    <p style="font-size:11.5px;color:var(--text-dim);margin-top:6px">0 в любом из полей — лимит выключен. Администраторов кулдаун на модерацию не касается — только простых модераторов.</p>
    <button class="btn btn-primary" type="submit" style="margin-top:10px">Сохранить</button>
  </form>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
