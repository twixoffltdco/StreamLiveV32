<?php
function flexlox_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $pdo = db();
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS flexlox_maps (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(120) NOT NULL,
        description VARCHAR(255) NULL,
        map_json MEDIUMTEXT NOT NULL,
        thumb_color VARCHAR(16) NULL DEFAULT '#00a2ff',
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        is_official TINYINT(1) NOT NULL DEFAULT 0,
        max_players INT UNSIGNED NOT NULL DEFAULT 20,
        plays INT UNSIGNED NOT NULL DEFAULT 0,
        likes INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_pub (is_published, plays), KEY idx_user (user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  foreach (['thumb_color VARCHAR(16) NULL','description VARCHAR(255) NULL','max_players INT UNSIGNED NOT NULL DEFAULT 20','likes INT UNSIGNED NOT NULL DEFAULT 0','is_official TINYINT(1) NOT NULL DEFAULT 0'] as $c) {
    try { $pdo->exec("ALTER TABLE flexlox_maps ADD COLUMN $c"); } catch (Throwable $e) {}
  }
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS flexlox_avatars (
        user_id INT UNSIGNED PRIMARY KEY,
        head_color VARCHAR(16) NOT NULL DEFAULT '#f5d0c5',
        torso_color VARCHAR(16) NOT NULL DEFAULT '#00a2ff',
        arms_color VARCHAR(16) NOT NULL DEFAULT '#f5d0c5',
        legs_color VARCHAR(16) NOT NULL DEFAULT '#1e3a5f',
        hat VARCHAR(32) NOT NULL DEFAULT 'none',
        face VARCHAR(32) NOT NULL DEFAULT 'smile',
        merch_url VARCHAR(512) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
  try { $pdo->exec('ALTER TABLE flexlox_avatars ADD COLUMN merch_url VARCHAR(512) NULL'); } catch (Throwable $e) {}
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flexlox_likes (
      user_id INT UNSIGNED NOT NULL, map_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, map_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flexlox_favorites (
      user_id INT UNSIGNED NOT NULL, map_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, map_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flexlox_continue (
      user_id INT UNSIGNED NOT NULL, map_id INT UNSIGNED NOT NULL,
      played_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, map_id), KEY idx_played (user_id, played_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}

  try {
    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM flexlox_maps WHERE is_official=1')->fetchColumn();
    if ($cnt === 0) {
      $uid = 1;
      try { $u = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn(); if ($u) $uid = (int)$u; } catch (Throwable $e) {}
      $st = $pdo->prepare('INSERT INTO flexlox_maps (user_id,title,description,map_json,is_published,is_official,thumb_color) VALUES (?,?,?,?,1,1,?)');
      $st->execute([$uid,'Baseplate','Open world без блоков.',json_encode(['mode'=>'open','template'=>'baseplate']),'#00a2ff']);
      $st->execute([$uid,'Obby Park','Паркур-obby.',json_encode(['mode'=>'open','template'=>'obby']),'#02b757']);
      $st->execute([$uid,'Flex Town','Городок + NPC.',json_encode(['mode'=>'open','template'=>'town']),'#a855f7']);
    }
  } catch (Throwable $e) {}
}

function flexlox_get_avatar(int $userId): array {
  flexlox_ensure_schema();
  $d = ['head_color'=>'#f5d0c5','torso_color'=>'#00a2ff','arms_color'=>'#f5d0c5','legs_color'=>'#1e3a5f','hat'=>'none','face'=>'smile','merch_url'=>''];
  if ($userId <= 0) return $d;
  try {
    $st = db()->prepare('SELECT * FROM flexlox_avatars WHERE user_id=?');
    $st->execute([$userId]);
    $r = $st->fetch();
    return $r ? array_merge($d, $r) : $d;
  } catch (Throwable $e) { return $d; }
}

function flexlox_save_avatar(int $userId, array $data): void {
  flexlox_ensure_schema();
  $h = preg_match('/^#[0-9a-fA-F]{6}$/', $data['head_color'] ?? '') ? $data['head_color'] : '#f5d0c5';
  $t = preg_match('/^#[0-9a-fA-F]{6}$/', $data['torso_color'] ?? '') ? $data['torso_color'] : '#00a2ff';
  $a = preg_match('/^#[0-9a-fA-F]{6}$/', $data['arms_color'] ?? '') ? $data['arms_color'] : '#f5d0c5';
  $l = preg_match('/^#[0-9a-fA-F]{6}$/', $data['legs_color'] ?? '') ? $data['legs_color'] : '#1e3a5f';
  $hat = in_array($data['hat'] ?? 'none', ['none','cap','crown','top'], true) ? $data['hat'] : 'none';
  $face = in_array($data['face'] ?? 'smile', ['smile','cool','angry','wink'], true) ? $data['face'] : 'smile';
  $merch = trim((string)($data['merch_url'] ?? ''));
  if ($merch !== '' && !preg_match('#^https?://#i', $merch)) $merch = '';
  if (mb_strlen($merch) > 512) $merch = '';
  db()->prepare(
    'INSERT INTO flexlox_avatars (user_id,head_color,torso_color,arms_color,legs_color,hat,face,merch_url)
     VALUES (?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE head_color=VALUES(head_color),torso_color=VALUES(torso_color),
     arms_color=VALUES(arms_color),legs_color=VALUES(legs_color),hat=VALUES(hat),face=VALUES(face),merch_url=VALUES(merch_url)'
  )->execute([$userId,$h,$t,$a,$l,$hat,$face,$merch ?: null]);
}

function flexlox_mark_continue(int $userId, int $mapId): void {
  if ($userId<=0||$mapId<=0) return;
  flexlox_ensure_schema();
  try {
    db()->prepare('INSERT INTO flexlox_continue (user_id,map_id,played_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE played_at=NOW()')
      ->execute([$userId,$mapId]);
  } catch (Throwable $e) {}
}

function flexlox_toggle_favorite(int $userId, int $mapId): bool {
  flexlox_ensure_schema();
  $st = db()->prepare('SELECT 1 FROM flexlox_favorites WHERE user_id=? AND map_id=?');
  $st->execute([$userId,$mapId]);
  if ($st->fetch()) {
    db()->prepare('DELETE FROM flexlox_favorites WHERE user_id=? AND map_id=?')->execute([$userId,$mapId]);
    return false;
  }
  db()->prepare('INSERT INTO flexlox_favorites (user_id,map_id) VALUES (?,?)')->execute([$userId,$mapId]);
  return true;
}

function flexlox_mp_ensure(): void {
  flexlox_ensure_schema();
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS flexlox_mp_players (
        map_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        username VARCHAR(64) NOT NULL DEFAULT '',
        x FLOAT NOT NULL DEFAULT 0,
        y FLOAT NOT NULL DEFAULT 1,
        z FLOAT NOT NULL DEFAULT 0,
        yaw FLOAT NOT NULL DEFAULT 0,
        head_color VARCHAR(16) NULL,
        torso_color VARCHAR(16) NULL,
        arms_color VARCHAR(16) NULL,
        legs_color VARCHAR(16) NULL,
        hat VARCHAR(32) NULL,
        merch_url VARCHAR(512) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (map_id, user_id),
        KEY idx_map_time (map_id, updated_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}
