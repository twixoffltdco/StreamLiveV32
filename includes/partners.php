<?php
/**
 * StreamLive партнёры: личный промокод (навсегда, без переименования),
 * активация только с личного аккаунта → платная подписка (лимит брендов 50 и т.д.),
 * реферальная ссылка, статистика. Админ/модер промокод партнёра не отклоняют.
 */
declare(strict_types=1);

/** Дней доступа после одной активации партнёрского кода */
const PARTNER_ACCESS_DAYS = 30;

function partners_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $pdo = db();
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS partner_promos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        code VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_partner_user (user_id),
        UNIQUE KEY uq_partner_code (code),
        KEY idx_code (code)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS partner_activations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        promo_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        access_until DATETIME NOT NULL,
        UNIQUE KEY uq_user_promo (user_id, promo_id),
        KEY idx_user_until (user_id, access_until),
        KEY idx_promo (promo_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS partner_referrals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        partner_user_id INT UNSIGNED NOT NULL,
        referred_user_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_referred (referred_user_id),
        KEY idx_partner (partner_user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}
  foreach (['deleted_at' => 'DATETIME NULL', 'delete_scheduled_purge_at' => 'DATETIME NULL', 'delete_reason' => 'VARCHAR(255) NULL'] as $col => $def) {
    try {
      $pdo->exec("ALTER TABLE users ADD COLUMN `{$col}` {$def}");
    } catch (Throwable $e) {}
  }
}

/** Активная партнёрская подписка пользователя (личный id) */
function partners_active_sub(int $userId): ?array {
  if ($userId <= 0) return null;
  partners_ensure_schema();
  try {
    $st = db()->prepare(
      "SELECT a.*, p.code, p.user_id AS partner_user_id
       FROM partner_activations a
       JOIN partner_promos p ON p.id = a.promo_id
       WHERE a.user_id = ? AND a.access_until > NOW()
       ORDER BY a.access_until DESC LIMIT 1"
    );
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function partners_has_active_sub(int $userId): bool {
  return partners_active_sub($userId) !== null;
}

/** Промокод партнёра (один на аккаунт) */
function partners_get_promo(int $userId): ?array {
  partners_ensure_schema();
  try {
    $st = db()->prepare('SELECT * FROM partner_promos WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

/**
 * Создать личный промокод один раз. Переименовать нельзя.
 * Код: латиница/цифры, 4–16 символов.
 */
function partners_create_promo(int $userId, string $code): array {
  partners_ensure_schema();
  if ($userId <= 0) return ['ok' => false, 'error' => 'Нужен личный аккаунт'];
  // запрет с бренд-сессии
  if (!empty($_SESSION['brand_act_as'])) {
    return ['ok' => false, 'error' => 'Создавать промокод можно только с личного аккаунта'];
  }
  $existing = partners_get_promo($userId);
  if ($existing) {
    return ['ok' => false, 'error' => 'У вас уже есть промокод «' . $existing['code'] . '» — переименовать нельзя'];
  }
  $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
  if (strlen($code) < 4 || strlen($code) > 16) {
    return ['ok' => false, 'error' => 'Код: 4–16 символов (латиница и цифры)'];
  }
  try {
    $chk = db()->prepare('SELECT id FROM partner_promos WHERE code = ?');
    $chk->execute([$code]);
    if ($chk->fetch()) {
      return ['ok' => false, 'error' => 'Такой код уже занят'];
    }
    db()->prepare('INSERT INTO partner_promos (user_id, code) VALUES (?,?)')->execute([$userId, $code]);
    return ['ok' => true, 'code' => $code];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Активация партнёрского промокода — только личный аккаунт, один раз на код.
 * Нельзя с brand_act_as.
 */
function partners_activate(int $userId, string $code): array {
  partners_ensure_schema();
  if ($userId <= 0) return ['ok' => false, 'error' => 'Войдите в личный аккаунт'];
  if (!empty($_SESSION['brand_act_as'])) {
    return ['ok' => false, 'error' => 'Активация только с личного аккаунта, не с бренда'];
  }
  // не активируем удалённым
  try {
    $u = db()->prepare('SELECT id, is_brand, deleted_at FROM users WHERE id = ?');
    $u->execute([$userId]);
    $row = $u->fetch();
    if (!$row || !empty($row['is_brand']) || !empty($row['deleted_at'])) {
      return ['ok' => false, 'error' => 'Активация недоступна для этого аккаунта'];
    }
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'Ошибка проверки аккаунта'];
  }
  $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
  if ($code === '') return ['ok' => false, 'error' => 'Введите промокод'];
  try {
    $st = db()->prepare('SELECT * FROM partner_promos WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $p = $st->fetch();
    if (!$p) return ['ok' => false, 'error' => 'Промокод не найден'];
    if ((int)$p['user_id'] === $userId) {
      return ['ok' => false, 'error' => 'Нельзя активировать свой собственный промокод'];
    }
    $chk = db()->prepare('SELECT id FROM partner_activations WHERE user_id = ? AND promo_id = ?');
    $chk->execute([$userId, (int)$p['id']]);
    if ($chk->fetch()) {
      return ['ok' => false, 'error' => 'Этот промокод уже был активирован на вашем аккаунте (один раз)'];
    }
    $until = date('Y-m-d H:i:s', time() + PARTNER_ACCESS_DAYS * 86400);
    db()->prepare(
      'INSERT INTO partner_activations (promo_id, user_id, access_until) VALUES (?,?,?)'
    )->execute([(int)$p['id'], $userId, $until]);
    return ['ok' => true, 'access_until' => $until, 'days' => PARTNER_ACCESS_DAYS, 'code' => $p['code']];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function partners_stats(int $partnerUserId): array {
  partners_ensure_schema();
  $out = ['activations' => 0, 'active_now' => 0, 'referrals' => 0, 'code' => null];
  $promo = partners_get_promo($partnerUserId);
  if (!$promo) return $out;
  $out['code'] = $promo['code'];
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM partner_activations WHERE promo_id = ?');
    $st->execute([(int)$promo['id']]);
    $out['activations'] = (int)$st->fetchColumn();
    $st = db()->prepare('SELECT COUNT(*) FROM partner_activations WHERE promo_id = ? AND access_until > NOW()');
    $st->execute([(int)$promo['id']]);
    $out['active_now'] = (int)$st->fetchColumn();
  } catch (Throwable $e) {}
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM partner_referrals WHERE partner_user_id = ?');
    $st->execute([$partnerUserId]);
    $out['referrals'] = (int)$st->fetchColumn();
  } catch (Throwable $e) {}
  return $out;
}

function partners_referral_link(string $code): string {
  $host = $_SERVER['HTTP_HOST'] ?? 'streamlive.local';
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  return $scheme . '://' . $host . '/auth/register.php?ref=' . rawurlencode($code);
}

/** Зафиксировать реферала при регистрации (cookie/session ref) */
function partners_capture_referral(int $newUserId): void {
  partners_ensure_schema();
  $ref = '';
  if (!empty($_SESSION['partner_ref'])) $ref = (string)$_SESSION['partner_ref'];
  elseif (!empty($_COOKIE['sl_partner_ref'])) $ref = (string)$_COOKIE['sl_partner_ref'];
  $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ref) ?? '');
  if ($ref === '' || $newUserId <= 0) return;
  try {
    $st = db()->prepare('SELECT user_id FROM partner_promos WHERE code = ? LIMIT 1');
    $st->execute([$ref]);
    $pid = (int)($st->fetchColumn() ?: 0);
    if ($pid <= 0 || $pid === $newUserId) return;
    db()->prepare(
      'INSERT IGNORE INTO partner_referrals (partner_user_id, referred_user_id) VALUES (?,?)'
    )->execute([$pid, $newUserId]);
  } catch (Throwable $e) {}
  unset($_SESSION['partner_ref']);
}

/** Сохранить ref из query в сессию/cookie (вызывать на публичных страницах) */
function partners_remember_ref_from_request(): void {
  $ref = trim((string)($_GET['ref'] ?? ''));
  if ($ref === '') return;
  $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ref) ?? '');
  if (strlen($ref) < 4) return;
  $_SESSION['partner_ref'] = $ref;
  @setcookie('sl_partner_ref', $ref, [
    'expires' => time() + 30 * 86400,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}
