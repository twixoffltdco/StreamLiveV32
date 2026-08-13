<?php
function flex_world_ensure(): void {
  $pdo = db();
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS flex_world_players (
      server_id TINYINT UNSIGNED NOT NULL,
      user_id INT NOT NULL,
      username VARCHAR(64) NOT NULL DEFAULT '',
      x FLOAT NOT NULL DEFAULT 0,
      y FLOAT NOT NULL DEFAULT 1,
      z FLOAT NOT NULL DEFAULT 0,
      yaw FLOAT NOT NULL DEFAULT 0,
      is_afk TINYINT(1) NOT NULL DEFAULT 0,
      has_crown TINYINT(1) NOT NULL DEFAULT 0,
      merch_url VARCHAR(512) NULL,
      hat VARCHAR(32) NULL,
      head_color VARCHAR(16) NULL,
      torso_color VARCHAR(16) NULL,
      activity VARCHAR(120) NOT NULL DEFAULT '',
      phone_url VARCHAR(512) NULL,
      phone_title VARCHAR(120) NULL,
      phone_on TINYINT(1) NOT NULL DEFAULT 0,
      map_id SMALLINT UNSIGNED NOT NULL DEFAULT 1,
      role VARCHAR(16) NOT NULL DEFAULT 'user',
      hp SMALLINT NOT NULL DEFAULT 100,
      weapon TINYINT NOT NULL DEFAULT 0,
      pending_dmg SMALLINT NOT NULL DEFAULT 0,
      pending_attacker VARCHAR(64) NULL,
      last_killer VARCHAR(64) NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (server_id, user_id),
      KEY idx_srv_time (server_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
  foreach ([
    "ALTER TABLE flex_world_players ADD COLUMN phone_url VARCHAR(512) NULL",
    "ALTER TABLE flex_world_players ADD COLUMN phone_title VARCHAR(120) NULL",
    "ALTER TABLE flex_world_players ADD COLUMN head_color VARCHAR(16) NULL",
    "ALTER TABLE flex_world_players ADD COLUMN torso_color VARCHAR(16) NULL",
    "ALTER TABLE flex_world_players ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'user'",
    "ALTER TABLE flex_world_players ADD COLUMN hp SMALLINT NOT NULL DEFAULT 100",
    "ALTER TABLE flex_world_players ADD COLUMN weapon TINYINT NOT NULL DEFAULT 0",
    "ALTER TABLE flex_world_players ADD COLUMN pending_dmg SMALLINT NOT NULL DEFAULT 0",
    "ALTER TABLE flex_world_players ADD COLUMN pending_attacker VARCHAR(64) NULL",
    "ALTER TABLE flex_world_players ADD COLUMN last_killer VARCHAR(64) NULL",
  ] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) {}
  }
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS flex_world_meta (
      user_id INT NOT NULL PRIMARY KEY,
      ip_hash CHAR(64) NOT NULL DEFAULT '',
      play_seconds INT UNSIGNED NOT NULL DEFAULT 0,
      last_payday_slot VARCHAR(20) NOT NULL DEFAULT '',
      last_hour_xp VARCHAR(20) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS flex_world_queue (
      user_id INT NOT NULL PRIMARY KEY,
      username VARCHAR(64) NOT NULL DEFAULT '',
      joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_joined (joined_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
  $pdo->exec(
    "CREATE TABLE IF NOT EXISTS flex_avatars (
      user_id INT NOT NULL PRIMARY KEY,
      head_color VARCHAR(16) NOT NULL DEFAULT '#f5d0c5',
      torso_color VARCHAR(16) NOT NULL DEFAULT '#00a2ff',
      arms_color VARCHAR(16) NOT NULL DEFAULT '#f5d0c5',
      legs_color VARCHAR(16) NOT NULL DEFAULT '#1e3a5f',
      hat VARCHAR(32) NOT NULL DEFAULT 'none',
      merch_url VARCHAR(512) NULL,
      accessory VARCHAR(32) NOT NULL DEFAULT 'none',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
}

function flex_world_ip_hash(): string {
  $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0';
  return hash('sha256', $ip . '|flex_world');
}

function flex_world_has_crown(int $uid): bool {
  if ($uid <= 0) return false;
  // Партнёрская подписка (partners) → корона в Flex World
  try {
    if (is_file(__DIR__ . '/partners.php')) {
      require_once __DIR__ . '/partners.php';
      if (function_exists('partners_has_active_sub') && partners_has_active_sub($uid)) {
        return true;
      }
    }
  } catch (Throwable $e) {}
  foreach ([
    "SELECT 1 FROM paid_activations WHERE user_id=? AND expires_at > NOW() LIMIT 1",
    "SELECT 1 FROM channel_promo_activations WHERE user_id=? AND expires_at > NOW() LIMIT 1",
    "SELECT 1 FROM promo_activations WHERE user_id=? AND expires_at > NOW() LIMIT 1",
  ] as $sql) {
    try {
      $st = db()->prepare($sql);
      $st->execute([$uid]);
      if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {}
  }
  return false;
}

function flex_avatar_get(int $uid): array {
  flex_world_ensure();
  $def = [
    'head_color' => '#f5d0c5', 'torso_color' => '#00a2ff',
    'arms_color' => '#f5d0c5', 'legs_color' => '#1e3a5f',
    'hat' => 'none', 'merch_url' => '', 'accessory' => 'none',
  ];
  try {
    $st = db()->prepare('SELECT * FROM flex_avatars WHERE user_id=?');
    $st->execute([$uid]);
    $row = $st->fetch();
    if ($row) return array_merge($def, $row);
  } catch (Throwable $e) {}
  // legacy flexlox
  if (is_file(__DIR__ . '/flexlox.php')) {
    try {
      require_once __DIR__ . '/flexlox.php';
      if (function_exists('flexlox_get_avatar')) {
        $a = flexlox_get_avatar($uid);
        if ($a) return array_merge($def, $a);
      }
    } catch (Throwable $e) {}
  }
  return $def;
}

function flex_avatar_save(int $uid, array $d): void {
  flex_world_ensure();
  $h = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($d['head_color'] ?? '')) ? $d['head_color'] : '#f5d0c5';
  $t = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($d['torso_color'] ?? '')) ? $d['torso_color'] : '#00a2ff';
  $a = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($d['arms_color'] ?? '')) ? $d['arms_color'] : '#f5d0c5';
  $l = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($d['legs_color'] ?? '')) ? $d['legs_color'] : '#1e3a5f';
  $hat = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($d['hat'] ?? 'none'))) ?: 'none';
  $acc = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($d['accessory'] ?? 'none'))) ?: 'none';
  $merch = trim((string)($d['merch_url'] ?? ''));
  if ($merch !== '' && !preg_match('#^https?://#i', $merch)) $merch = '';
  if (mb_strlen($merch) > 500) $merch = '';
  db()->prepare(
    'INSERT INTO flex_avatars (user_id, head_color, torso_color, arms_color, legs_color, hat, merch_url, accessory)
     VALUES (?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE head_color=VALUES(head_color), torso_color=VALUES(torso_color),
       arms_color=VALUES(arms_color), legs_color=VALUES(legs_color), hat=VALUES(hat),
       merch_url=VALUES(merch_url), accessory=VALUES(accessory)'
  )->execute([$uid, $h, $t, $a, $l, $hat, $merch !== '' ? $merch : null, $acc]);
}

function flex_world_servers(): array {
  flex_world_ensure();
  $map = [];
  try {
    $rows = db()->query(
      "SELECT server_id, COUNT(*) AS c FROM flex_world_players
       WHERE updated_at > (NOW() - INTERVAL 20 SECOND) GROUP BY server_id"
    )->fetchAll() ?: [];
    foreach ($rows as $r) $map[(int)$r['server_id']] = (int)$r['c'];
  } catch (Throwable $e) {}
  $out = [];
  for ($i = 1; $i <= 10; $i++) {
    $c = $map[$i] ?? 0;
    $out[] = ['id' => $i, 'players' => $c, 'max' => 10, 'full' => $c >= 10];
  }
  return $out;
}

function flex_world_is_staff(?array $u): bool {
  if (!$u) return false;
  return in_array((string)($u['role'] ?? ''), ['admin', 'moderator'], true);
}

function flex_world_queue_pos(int $uid): int {
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM flex_world_queue WHERE joined_at <= (SELECT joined_at FROM flex_world_queue WHERE user_id=?)');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) { return 0; }
}
