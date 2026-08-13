<?php
/**
 * StreamLive бренды — аккаунты организаций.
 * Базовый лимит 20; при is_verified или активной партнёрской подписке — 50.
 * Бренд = отдельная запись users (is_brand=1), посты идут от user_id бренда.
 */

declare(strict_types=1);

const BRANDS_MAX_DEFAULT = 20;
const BRANDS_MAX_PREMIUM = 50;

function brands_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $pdo = db();
  foreach ([
    'is_brand' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'brand_owner_id' => 'INT UNSIGNED NULL',
    'brand_verified' => 'TINYINT(1) NOT NULL DEFAULT 1',
    'deleted_at' => 'DATETIME NULL',
    'delete_scheduled_purge_at' => 'DATETIME NULL',
    'delete_reason' => 'VARCHAR(255) NULL',
  ] as $col => $def) {
    try {
      $pdo->exec("ALTER TABLE users ADD COLUMN `{$col}` {$def}");
    } catch (Throwable $e) { /* exists */ }
  }
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS brand_members (
        brand_user_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        role ENUM('owner','admin','editor') NOT NULL DEFAULT 'editor',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (brand_user_id, user_id),
        KEY idx_member (user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}
}

/** Лимит брендов: 50 если галочка или активная партнёрская подписка, иначе 20 */
function brands_max_for_user(int $realUid): int {
  if ($realUid <= 0) return BRANDS_MAX_DEFAULT;
  try {
    $st = db()->prepare('SELECT is_verified FROM users WHERE id = ?');
    $st->execute([$realUid]);
    $row = $st->fetch();
    if ($row && !empty($row['is_verified'])) return BRANDS_MAX_PREMIUM;
  } catch (Throwable $e) {}
  if (is_file(__DIR__ . '/partners.php')) {
    require_once __DIR__ . '/partners.php';
    if (function_exists('partners_has_active_sub') && partners_has_active_sub($realUid)) {
      return BRANDS_MAX_PREMIUM;
    }
  }
  return BRANDS_MAX_DEFAULT;
}

function brands_real_user_id(): int {
  return (int)($_SESSION['user_id'] ?? 0);
}

/** Личный аккаунт (всегда владелец сессии), без act-as */
function brands_real_user(): ?array {
  $uid = brands_real_user_id();
  if ($uid <= 0) return null;
  static $cache = [];
  if (isset($cache[$uid])) return $cache[$uid];
  try {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch() ?: null;
    if ($u && !empty($u['is_banned'])) $u = null;
    $cache[$uid] = $u;
    return $u;
  } catch (Throwable $e) {
    return null;
  }
}

function brands_can_manage(int $realUid, int $brandUserId): bool {
  if ($realUid <= 0 || $brandUserId <= 0) return false;
  brands_ensure_schema();
  try {
    $st = db()->prepare('SELECT brand_owner_id, is_brand FROM users WHERE id = ?');
    $st->execute([$brandUserId]);
    $b = $st->fetch();
    if (!$b || empty($b['is_brand'])) return false;
    if ((int)($b['brand_owner_id'] ?? 0) === $realUid) return true;
    $m = db()->prepare('SELECT role FROM brand_members WHERE brand_user_id = ? AND user_id = ?');
    $m->execute([$brandUserId, $realUid]);
    $row = $m->fetch();
    return $row && in_array($row['role'], ['owner', 'admin', 'editor'], true);
  } catch (Throwable $e) {
    return false;
  }
}

function brands_role(int $realUid, int $brandUserId): ?string {
  brands_ensure_schema();
  try {
    $st = db()->prepare('SELECT brand_owner_id, is_brand FROM users WHERE id = ?');
    $st->execute([$brandUserId]);
    $b = $st->fetch();
    if (!$b || empty($b['is_brand'])) return null;
    if ((int)($b['brand_owner_id'] ?? 0) === $realUid) return 'owner';
    $m = db()->prepare('SELECT role FROM brand_members WHERE brand_user_id = ? AND user_id = ?');
    $m->execute([$brandUserId, $realUid]);
    $row = $m->fetch();
    return $row ? (string)$row['role'] : null;
  } catch (Throwable $e) {
    return null;
  }
}

function brands_list_for_user(int $realUid): array {
  brands_ensure_schema();
  if ($realUid <= 0) return [];
  try {
    $st = db()->prepare(
      "SELECT u.* FROM users u
       WHERE u.is_brand = 1 AND (u.deleted_at IS NULL) AND (
         u.brand_owner_id = ?
         OR EXISTS (SELECT 1 FROM brand_members m WHERE m.brand_user_id = u.id AND m.user_id = ?)
       )
       ORDER BY u.username ASC"
    );
    $st->execute([$realUid, $realUid]);
    return $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function brands_owned_count(int $realUid): int {
  brands_ensure_schema();
  try {
    $st = db()->prepare(
      'SELECT COUNT(*) FROM users WHERE is_brand = 1 AND brand_owner_id = ? AND (deleted_at IS NULL)'
    );
    $st->execute([$realUid]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) {
    try {
      $st = db()->prepare('SELECT COUNT(*) FROM users WHERE is_brand = 1 AND brand_owner_id = ?');
      $st->execute([$realUid]);
      return (int)$st->fetchColumn();
    } catch (Throwable $e2) {
      return 0;
    }
  }
}

function brands_create(int $ownerUid, string $username, string $displayName = ''): array {
  brands_ensure_schema();
  $username = trim($username);
  $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username) ?? '';
  if (strlen($username) < 3 || strlen($username) > 24) {
    return ['ok' => false, 'error' => 'Ник бренда: 3–24 символа (латиница, цифры, _)'];
  }
  $max = brands_max_for_user($ownerUid);
  if (brands_owned_count($ownerUid) >= $max) {
    return ['ok' => false, 'error' => 'Лимит: максимум ' . $max . ' бренд-аккаунтов' . ($max < BRANDS_MAX_PREMIUM ? ' (галочка или партнёрский промокод → до ' . BRANDS_MAX_PREMIUM . ')' : '')];
  }
  $pdo = db();
  $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
  $check->execute([$username]);
  if ($check->fetch()) {
    return ['ok' => false, 'error' => 'Такой ник уже занят'];
  }
  $email = 'brand+' . strtolower($username) . '.' . $ownerUid . '@streamlive.local';
  $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
  $display = trim($displayName) !== '' ? trim($displayName) : $username;
  try {
    $pdo->prepare(
      'INSERT INTO users (username, email, password_hash, is_brand, brand_owner_id, brand_verified, created_at)
       VALUES (?,?,?,1,?,1,NOW())'
    )->execute([$username, $email, $hash, $ownerUid]);
  } catch (Throwable $e) {
    // fallback if created_at / brand_verified missing
    try {
      $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, is_brand, brand_owner_id) VALUES (?,?,?,1,?)'
      )->execute([$username, $email, $hash, $ownerUid]);
    } catch (Throwable $e2) {
      return ['ok' => false, 'error' => 'Не удалось создать: ' . $e2->getMessage()];
    }
  }
  $id = (int)$pdo->lastInsertId();
  try {
    $pdo->prepare(
      'INSERT INTO brand_members (brand_user_id, user_id, role) VALUES (?,?,\'owner\')
       ON DUPLICATE KEY UPDATE role=\'owner\''
    )->execute([$id, $ownerUid]);
  } catch (Throwable $e) {}
  // optional display name in bio if column exists
  try {
    $pdo->prepare('UPDATE users SET bio = ? WHERE id = ?')->execute(['Бренд: ' . $display, $id]);
  } catch (Throwable $e) {}
  return ['ok' => true, 'id' => $id, 'username' => $username];
}

function brands_switch(?int $brandUserId): bool {
  $real = brands_real_user_id();
  if ($real <= 0) return false;
  if ($brandUserId === null || $brandUserId <= 0) {
    unset($_SESSION['brand_act_as']);
    return true;
  }
  if (!brands_can_manage($real, $brandUserId)) return false;
  $_SESSION['brand_act_as'] = $brandUserId;
  return true;
}

function brands_active_id(): int {
  return (int)($_SESSION['brand_act_as'] ?? 0);
}

function brands_get(int $brandUserId): ?array {
  brands_ensure_schema();
  try {
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_brand = 1');
    $st->execute([$brandUserId]);
    return $st->fetch() ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function brands_add_member(int $brandUserId, int $memberUid, string $role = 'editor'): array {
  $role = in_array($role, ['admin', 'editor'], true) ? $role : 'editor';
  brands_ensure_schema();
  $real = brands_real_user_id();
  $my = brands_role($real, $brandUserId);
  if (!in_array($my, ['owner', 'admin'], true)) {
    return ['ok' => false, 'error' => 'Недостаточно прав'];
  }
  if ($memberUid <= 0) return ['ok' => false, 'error' => 'Пользователь не найден'];
  try {
    db()->prepare(
      'INSERT INTO brand_members (brand_user_id, user_id, role) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE role=VALUES(role)'
    )->execute([$brandUserId, $memberUid, $role]);
    return ['ok' => true];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function brands_owner_username(int $brandUserId): string {
  try {
    $st = db()->prepare(
      'SELECT u.username FROM users b JOIN users u ON u.id = b.brand_owner_id WHERE b.id = ?'
    );
    $st->execute([$brandUserId]);
    return (string)($st->fetchColumn() ?: '');
  } catch (Throwable $e) {
    return '';
  }
}

/** Смена роли участника (owner/admin). Нельзя снять единственного owner через эту функцию — только transfer. */
function brands_set_member_role(int $brandUserId, int $memberUid, string $role): array {
  $role = in_array($role, ['admin', 'editor'], true) ? $role : 'editor';
  $real = brands_real_user_id();
  if (brands_role($real, $brandUserId) !== 'owner') {
    return ['ok' => false, 'error' => 'Только владелец меняет роли'];
  }
  if ($memberUid === $real) {
    return ['ok' => false, 'error' => 'Свою роль владельца менять нельзя — используйте передачу'];
  }
  try {
    $st = db()->prepare('UPDATE brand_members SET role = ? WHERE brand_user_id = ? AND user_id = ?');
    $st->execute([$role, $brandUserId, $memberUid]);
    if ($st->rowCount() === 0) {
      return ['ok' => false, 'error' => 'Участник не найден'];
    }
    return ['ok' => true];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function brands_remove_member(int $brandUserId, int $memberUid): array {
  $real = brands_real_user_id();
  if (brands_role($real, $brandUserId) !== 'owner') {
    return ['ok' => false, 'error' => 'Только владелец'];
  }
  if ($memberUid === $real) {
    return ['ok' => false, 'error' => 'Владельца нельзя удалить — передайте бренд'];
  }
  try {
    db()->prepare('DELETE FROM brand_members WHERE brand_user_id = ? AND user_id = ?')
      ->execute([$brandUserId, $memberUid]);
    return ['ok' => true];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Передача владельца бренда другому пользователю (личный аккаунт).
 * Новый владелец получает role=owner, старый — admin.
 */
function brands_transfer_ownership(int $brandUserId, int $newOwnerUid): array {
  brands_ensure_schema();
  $real = brands_real_user_id();
  if (brands_role($real, $brandUserId) !== 'owner') {
    return ['ok' => false, 'error' => 'Только текущий владелец может передать бренд'];
  }
  if ($newOwnerUid <= 0 || $newOwnerUid === $real) {
    return ['ok' => false, 'error' => 'Укажите другого пользователя'];
  }
  try {
    $st = db()->prepare('SELECT id, is_brand, deleted_at FROM users WHERE id = ?');
    $st->execute([$newOwnerUid]);
    $nu = $st->fetch();
    if (!$nu || !empty($nu['is_brand']) || !empty($nu['deleted_at'])) {
      return ['ok' => false, 'error' => 'Получатель должен быть обычным (не бренд) аккаунтом'];
    }
    $max = brands_max_for_user($newOwnerUid);
    if (brands_owned_count($newOwnerUid) >= $max) {
      return ['ok' => false, 'error' => 'У получателя достигнут лимит брендов (' . $max . ')'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET brand_owner_id = ? WHERE id = ? AND is_brand = 1')
      ->execute([$newOwnerUid, $brandUserId]);
    $pdo->prepare(
      "INSERT INTO brand_members (brand_user_id, user_id, role) VALUES (?,?, 'owner')
       ON DUPLICATE KEY UPDATE role = 'owner'"
    )->execute([$brandUserId, $newOwnerUid]);
    $pdo->prepare(
      "INSERT INTO brand_members (brand_user_id, user_id, role) VALUES (?,?, 'admin')
       ON DUPLICATE KEY UPDATE role = 'admin'"
    )->execute([$brandUserId, $real]);
    $pdo->commit();
    if (brands_active_id() === $brandUserId) {
      // остаёмся на бренде, если всё ещё member
    }
    return ['ok' => true];
  } catch (Throwable $e) {
    try { db()->rollBack(); } catch (Throwable $e2) {}
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Soft-delete бренда. Данные хранятся 60 дней, затем purge.
 * Только owner.
 */
function brands_delete(int $brandUserId, string $reason = ''): array {
  brands_ensure_schema();
  $real = brands_real_user_id();
  if (brands_role($real, $brandUserId) !== 'owner') {
    return ['ok' => false, 'error' => 'Только владелец может удалить бренд'];
  }
  $purgeAt = date('Y-m-d H:i:s', time() + 60 * 86400);
  try {
    db()->prepare(
      'UPDATE users SET deleted_at = NOW(), delete_scheduled_purge_at = ?, delete_reason = ?, is_banned = 1
       WHERE id = ? AND is_brand = 1'
    )->execute([$purgeAt, mb_substr($reason, 0, 250), $brandUserId]);
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
  if (brands_active_id() === $brandUserId) {
    unset($_SESSION['brand_act_as']);
  }
  return ['ok' => true, 'purge_at' => $purgeAt];
}

/**
 * Soft-delete личного аккаунта. 60 дней хранения (ФЗ «Яровая»).
 * Нельзя будучи в brand_act_as.
 */
function brands_schedule_account_delete(int $userId, string $reason = ''): array {
  brands_ensure_schema();
  if (!empty($_SESSION['brand_act_as'])) {
    return ['ok' => false, 'error' => 'Сначала переключитесь на личный аккаунт'];
  }
  if ($userId <= 0 || $userId !== brands_real_user_id()) {
    return ['ok' => false, 'error' => 'Можно удалить только свой аккаунт'];
  }
  try {
    $st = db()->prepare('SELECT is_brand, role FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u || !empty($u['is_brand'])) {
      return ['ok' => false, 'error' => 'Неверный аккаунт'];
    }
    if (($u['role'] ?? '') === 'admin') {
      return ['ok' => false, 'error' => 'Админ-аккаунт так не удаляется'];
    }
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
  $purgeAt = date('Y-m-d H:i:s', time() + 60 * 86400);
  try {
    db()->prepare(
      'UPDATE users SET deleted_at = NOW(), delete_scheduled_purge_at = ?, delete_reason = ?, is_banned = 1
       WHERE id = ?'
    )->execute([$purgeAt, mb_substr($reason, 0, 250), $userId]);
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
  // soft-delete owned brands too
  try {
    db()->prepare(
      "UPDATE users SET deleted_at = NOW(), delete_scheduled_purge_at = ?, delete_reason = 'owner_account_deleted', is_banned = 1
       WHERE is_brand = 1 AND brand_owner_id = ? AND deleted_at IS NULL"
    )->execute([$purgeAt, $userId]);
  } catch (Throwable $e) {}
  return ['ok' => true, 'purge_at' => $purgeAt];
}
