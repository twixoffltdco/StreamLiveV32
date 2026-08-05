<?php
/**
 * Платный контент канала + видео того же канала.
 * Активация промокода = доступ на 30 дней, потом снова ввод кода.
 */
if (!function_exists('paid_ensure_schema')) {

function paid_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec('ALTER TABLE channels ADD COLUMN paid_content TINYINT(1) NOT NULL DEFAULT 0');
  } catch (Throwable $e) {}
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS promo_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(64) NOT NULL,
        channel_id INT UNSIGNED NULL COMMENT 'NULL = все платные каналы',
        created_by INT UNSIGNED NOT NULL,
        max_uses INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = без лимита',
        uses_count INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        expires_at DATETIME NULL,
        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code (code),
        KEY idx_creator (created_by),
        KEY idx_channel (channel_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS promo_activations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        promo_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        channel_id INT UNSIGNED NULL,
        accepted_terms TINYINT(1) NOT NULL DEFAULT 1,
        is_revoked TINYINT(1) NOT NULL DEFAULT 0,
        revoked_by INT UNSIGNED NULL,
        revoked_at DATETIME NULL,
        activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        access_until DATETIME NULL COMMENT 'конец доступа (30 дней)',
        UNIQUE KEY uq_user_promo (user_id, promo_id),
        KEY idx_user (user_id),
        KEY idx_promo (promo_id),
        KEY idx_until (access_until)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  // старые таблицы без access_until
  try {
    db()->exec('ALTER TABLE promo_activations ADD COLUMN access_until DATETIME NULL');
  } catch (Throwable $e) {}
  try {
    // старые активации без срока → 30 дней от activated_at
    db()->exec("UPDATE promo_activations SET access_until = DATE_ADD(activated_at, INTERVAL 30 DAY) WHERE access_until IS NULL AND is_revoked = 0");
  } catch (Throwable $e) {}

  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS mod_promo_creates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        moderator_id INT UNSIGNED NOT NULL,
        promo_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mod (moderator_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function paid_channel_is_paid(array $channel): bool {
  return !empty($channel['paid_content']);
}

/** Канал платный? по id */
function paid_channel_id_is_paid(int $channelId): bool {
  if ($channelId <= 0) return false;
  paid_ensure_schema();
  try {
    $st = db()->prepare('SELECT paid_content FROM channels WHERE id = ?');
    $st->execute([$channelId]);
    $r = $st->fetch();
    return $r && !empty($r['paid_content']);
  } catch (Throwable $e) {
    return false;
  }
}

function paid_user_can_watch(?array $user, array $channel): bool {
  paid_ensure_schema();
  if (!paid_channel_is_paid($channel)) return true;
  if (!$user) return false;
  $uid = (int)($user['id'] ?? 0);
  if ($uid <= 0) return false;

  $role = (string)($user['role'] ?? '');
  if (in_array($role, ['admin', 'moderator'], true)) return true;
  if ((int)($channel['owner_id'] ?? 0) === $uid) return true;

  $cid = (int)($channel['id'] ?? 0);
  try {
    $st = db()->prepare(
      "SELECT a.id FROM promo_activations a
       JOIN promo_codes p ON p.id = a.promo_id
       WHERE a.user_id = ? AND a.is_revoked = 0 AND p.is_active = 1
         AND (p.expires_at IS NULL OR p.expires_at > NOW())
         AND (a.access_until IS NOT NULL AND a.access_until > NOW())
         AND (a.channel_id IS NULL OR a.channel_id = ? OR p.channel_id IS NULL OR p.channel_id = ?)
       LIMIT 1"
    );
    $st->execute([$uid, $cid, $cid]);
    return (bool)$st->fetch();
  } catch (Throwable $e) {
    return false;
  }
}

/** Доступ к видео = доступ к каналу видео (если канал платный) */
function paid_user_can_watch_video(?array $user, array $video): bool {
  paid_ensure_schema();
  $cid = (int)($video['channel_id'] ?? 0);
  if ($cid <= 0) return true;
  if (!paid_channel_id_is_paid($cid)) return true;

  // собрать channel-like array
  $channel = [
    'id' => $cid,
    'paid_content' => 1,
    'owner_id' => 0,
    'title' => $video['channel_title'] ?? 'Канал',
    'slug' => $video['channel_slug'] ?? '',
  ];
  try {
    $st = db()->prepare('SELECT id, owner_id, title, slug, paid_content FROM channels WHERE id = ?');
    $st->execute([$cid]);
    $ch = $st->fetch();
    if ($ch) $channel = $ch;
  } catch (Throwable $e) {}
  return paid_user_can_watch($user, $channel);
}

function paid_activate_code(int $userId, string $code, int $channelId, bool $acceptTerms): array {
  paid_ensure_schema();
  if (!$acceptTerms) {
    return ['ok' => false, 'error' => 'Нужно принять условия использования'];
  }
  $code = strtoupper(trim($code));
  if ($code === '' || $userId <= 0) {
    return ['ok' => false, 'error' => 'Введите промокод'];
  }
  try {
    $st = db()->prepare('SELECT * FROM promo_codes WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $p = $st->fetch();
    if (!$p || !(int)$p['is_active']) {
      return ['ok' => false, 'error' => 'Промокод недействителен'];
    }
    if (!empty($p['expires_at']) && strtotime($p['expires_at']) < time()) {
      return ['ok' => false, 'error' => 'Срок промокода истёк'];
    }
    $max = (int)$p['max_uses'];
    if ($max > 0 && (int)$p['uses_count'] >= $max) {
      return ['ok' => false, 'error' => 'Лимит активаций исчерпан'];
    }
    $pCh = $p['channel_id'] !== null ? (int)$p['channel_id'] : null;
    if ($pCh !== null && $pCh !== $channelId) {
      return ['ok' => false, 'error' => 'Промокод не для этого канала'];
    }

    $until = date('Y-m-d H:i:s', time() + 30 * 86400); // 30 дней

    $chk = db()->prepare('SELECT id, is_revoked, access_until FROM promo_activations WHERE user_id = ? AND promo_id = ?');
    $chk->execute([$userId, (int)$p['id']]);
    $ex = $chk->fetch();

    if ($ex && !(int)$ex['is_revoked'] && !empty($ex['access_until']) && strtotime($ex['access_until']) > time()) {
      return [
        'ok' => true,
        'message' => 'Доступ уже активен до ' . date('d.m.Y H:i', strtotime($ex['access_until'])),
        'access_until' => $ex['access_until'],
      ];
    }

    if ($ex) {
      // повторная активация / продление на 30 дней
      db()->prepare(
        'UPDATE promo_activations SET is_revoked=0, revoked_by=NULL, revoked_at=NULL,
         accepted_terms=1, channel_id=?, activated_at=NOW(), access_until=? WHERE id=?'
      )->execute([$channelId, $until, (int)$ex['id']]);
      // uses_count не увеличиваем при реактивации того же кода тем же юзером
    } else {
      db()->prepare(
        'INSERT INTO promo_activations (promo_id, user_id, channel_id, accepted_terms, access_until)
         VALUES (?,?,?,1,?)'
      )->execute([(int)$p['id'], $userId, $channelId, $until]);
      db()->prepare('UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = ?')->execute([(int)$p['id']]);
    }
    return [
      'ok' => true,
      'message' => 'Доступ открыт на 30 дней (до ' . date('d.m.Y H:i', strtotime($until)) . ')',
      'access_until' => $until,
    ];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'Ошибка: ' . $e->getMessage()];
  }
}

function paid_revoke_activation(int $activationId, int $byUserId): bool {
  paid_ensure_schema();
  try {
    db()->prepare(
      'UPDATE promo_activations SET is_revoked=1, revoked_by=?, revoked_at=NOW(), access_until=NOW() WHERE id=?'
    )->execute([$byUserId, $activationId]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function paid_gate_form(array $channel, ?array $user, string $context = 'channel'): void {
  $title = $channel['title'] ?? 'Контент';
  $pageTitle = 'Платный контент';
  require_once __DIR__ . '/header.php';
  $flash = function_exists('flash_get') ? flash_get() : [];
  ?>
  <div class="container" style="max-width:480px;padding:40px 16px">
    <div class="form-card">
      <h2>Платный / закрытый контент</h2>
      <p style="color:var(--text-dim);font-size:14px">
        «<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>» доступен по промокоду.
        После активации доступ действует <b>30 дней</b>, затем нужно снова ввести код.
      </p>
      <?php foreach ($flash as $type => $msg): ?>
        <div class="alert alert-<?= htmlspecialchars((string)$type) ?>"><?= htmlspecialchars((string)$msg) ?></div>
      <?php endforeach; ?>
      <?php if (!$user): ?>
        <p><a class="btn btn-primary" href="/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>">Войти</a></p>
      <?php else: ?>
        <form method="post">
          <?= function_exists('csrf_field') ? csrf_field() : '' ?>
          <input type="hidden" name="action" value="activate_promo">
          <label>Промокод
            <input name="promo_code" required maxlength="64" style="text-transform:uppercase" placeholder="XXXX-XXXX" autocomplete="off">
          </label>
          <label style="display:flex;gap:8px;align-items:flex-start;margin:14px 0;font-weight:400;font-size:13px">
            <input type="checkbox" name="accept_terms" value="1" required style="width:auto;margin-top:3px">
            <span>Принимаю условия. Администрация/модераторы могут отозвать доступ. Через 30 дней доступ истекает.</span>
          </label>
          <button class="btn btn-primary" type="submit">Активировать на 30 дней</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php
  require_once __DIR__ . '/footer.php';
  exit;
}

function paid_require_access(array $channel, ?array $user): void {
  paid_ensure_schema();
  if (!paid_channel_is_paid($channel)) return;
  if (paid_user_can_watch($user, $channel)) return;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate_promo') {
    if (function_exists('csrf_verify')) csrf_verify();
    if (!$user) {
      if (function_exists('flash_set')) flash_set('error', 'Войдите, чтобы активировать промокод');
      redirect('/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }
    $res = paid_activate_code(
      (int)$user['id'],
      (string)($_POST['promo_code'] ?? ''),
      (int)$channel['id'],
      !empty($_POST['accept_terms'])
    );
    if ($res['ok']) {
      if (function_exists('flash_set')) flash_set('success', $res['message'] ?? 'OK');
      redirect($_SERVER['REQUEST_URI'] ?? ('/channel.php?slug=' . urlencode($channel['slug'] ?? '')));
    }
    if (function_exists('flash_set')) flash_set('error', $res['error'] ?? 'Ошибка');
    redirect($_SERVER['REQUEST_URI'] ?? '/');
  }

  paid_gate_form($channel, $user, 'channel');
}

/** Гейт для страницы видео (по paid_content канала) */
function paid_require_video_access(array $video, ?array $user): void {
  paid_ensure_schema();
  $cid = (int)($video['channel_id'] ?? 0);
  if ($cid <= 0 || !paid_channel_id_is_paid($cid)) return;

  $channel = [
    'id' => $cid,
    'paid_content' => 1,
    'owner_id' => 0,
    'title' => ($video['title'] ?? 'Видео') . ' (канал)',
    'slug' => $video['channel_slug'] ?? '',
  ];
  try {
    $st = db()->prepare('SELECT * FROM channels WHERE id = ?');
    $st->execute([$cid]);
    $ch = $st->fetch();
    if ($ch) {
      $channel = $ch;
      // для формы показываем что это видео
      $channel['title'] = ($video['title'] ?? 'Видео') . ' · ' . ($ch['title'] ?? '');
    }
  } catch (Throwable $e) {}

  if (paid_user_can_watch($user, $channel)) return;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate_promo') {
    if (function_exists('csrf_verify')) csrf_verify();
    if (!$user) {
      if (function_exists('flash_set')) flash_set('error', 'Войдите, чтобы активировать промокод');
      redirect('/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }
    $res = paid_activate_code(
      (int)$user['id'],
      (string)($_POST['promo_code'] ?? ''),
      $cid,
      !empty($_POST['accept_terms'])
    );
    if ($res['ok']) {
      if (function_exists('flash_set')) flash_set('success', $res['message'] ?? 'OK');
      redirect($_SERVER['REQUEST_URI'] ?? ('/video.php?slug=' . rawurlencode($video['slug'] ?? '')));
    }
    if (function_exists('flash_set')) flash_set('error', $res['error'] ?? 'Ошибка');
    redirect($_SERVER['REQUEST_URI'] ?? '/');
  }

  paid_gate_form($channel, $user, 'video');
}

} // function_exists
