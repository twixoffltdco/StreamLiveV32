<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/flex_world.php';

$u = current_user();
if (!$u) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'login']); exit; }
flex_world_ensure();
try { db()->exec('ALTER TABLE flex_world_meta ADD COLUMN last_daily_date DATE NULL'); } catch (Throwable $e) {}
try { db()->exec('ALTER TABLE flex_world_meta ADD COLUMN daily_streak TINYINT NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
$uid = (int)$u['id'];
$j = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($j)) $j = [];
$action = (string)($j['action'] ?? 'sync');
if ($action === 'quest_xp') {
  $xp = max(0, min(50000, (int)($j['xp'] ?? 0)));
  if ($xp > 0) { try { db()->prepare('UPDATE users SET xp = COALESCE(xp,0) + ? WHERE id=?')->execute([$xp, $uid]); } catch (Throwable $e) {} }
  echo json_encode(['ok'=>true,'xp'=>$xp]); exit;
}


try { db()->exec("CREATE TABLE IF NOT EXISTS flex_world_builds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  server_id TINYINT UNSIGNED NOT NULL,
  map_id SMALLINT UNSIGNED NOT NULL,
  x INT NOT NULL, y INT NOT NULL, z INT NOT NULL,
  color INT UNSIGNED NOT NULL DEFAULT 3947580,
  user_id INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pos (server_id, map_id, x, y, z)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
if ($action === 'build_list') {
  $blocks=[];
  try { $st=db()->prepare('SELECT x,y,z,color FROM flex_world_builds WHERE server_id=? AND map_id=? LIMIT 500'); $st->execute([$server,$mapId]); $blocks=$st->fetchAll()?:[]; } catch(Throwable $e){}
  echo json_encode(['ok'=>true,'blocks'=>$blocks], JSON_UNESCAPED_UNICODE); exit;
}
if ($action === 'build_add') {
  $x=(int)($j['x']??0); $y=max(0,min(40,(int)($j['y']??0))); $z=(int)($j['z']??0); $color=(int)($j['color']??0x3b82f6);
  try {
    $c=db()->prepare('SELECT COUNT(*) FROM flex_world_builds WHERE server_id=? AND map_id=?'); $c->execute([$server,$mapId]);
    if ((int)$c->fetchColumn()>=500) { echo json_encode(['ok'=>false]); exit; }
    db()->prepare('INSERT INTO flex_world_builds (server_id,map_id,x,y,z,color,user_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE color=VALUES(color)')->execute([$server,$mapId,$x,$y,$z,$color,$uid]);
  } catch(Throwable $e) {}
  echo json_encode(['ok'=>true]); exit;
}
if ($action === 'build_del') {
  try { db()->prepare('DELETE FROM flex_world_builds WHERE server_id=? AND map_id=? AND x=? AND y=? AND z=?')->execute([$server,$mapId,(int)($j['x']??0),(int)($j['y']??0),(int)($j['z']??0)]); } catch(Throwable $e){}
  echo json_encode(['ok'=>true]); exit;
}

$server = max(1, min(10, (int)($j['server'] ?? 1)));
$mapId = max(1, min(100, (int)($j['map'] ?? 1)));

// ensure combat columns
try {
  $pdo = db();
  foreach ([
    "ALTER TABLE flex_world_players ADD COLUMN hp SMALLINT NOT NULL DEFAULT 100",
    "ALTER TABLE flex_world_players ADD COLUMN weapon TINYINT NOT NULL DEFAULT 0",
    "ALTER TABLE flex_world_players ADD COLUMN pending_dmg SMALLINT NOT NULL DEFAULT 0",
    "ALTER TABLE flex_world_players ADD COLUMN pending_attacker VARCHAR(64) NULL",
    "ALTER TABLE flex_world_players ADD COLUMN last_killer VARCHAR(64) NULL",
  ] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) {}
  }
} catch (Throwable $e) {}

if ($action === 'leave') {
  try { db()->prepare('DELETE FROM flex_world_players WHERE user_id=?')->execute([$uid]); } catch (Throwable $e) {}
  echo json_encode(['ok'=>true]); exit;
}

// --- HIT action ---
if ($action === 'hit') {
  $targetId = (int)($j['target_id'] ?? 0);
  $damage = max(1, min(80, (int)($j['damage'] ?? 10)));
  $weapon = max(0, min(2, (int)($j['weapon'] ?? 0)));
  if ($targetId <= 0 || $targetId === $uid) {
    echo json_encode(['ok'=>false,'error'=>'bad_target']); exit;
  }
  try {
    // must be same server and recent
    $st = db()->prepare(
      'SELECT user_id, username, hp, x, y, z FROM flex_world_players
       WHERE user_id=? AND server_id=? AND updated_at > (NOW() - INTERVAL 20 SECOND)'
    );
    $st->execute([$targetId, $server]);
    $t = $st->fetch();
    if (!$t) {
      echo json_encode(['ok'=>false,'error'=>'offline']); exit;
    }
    // simple distance check (server-side soft)
    $mx = (float)($j['x'] ?? 0); $mz = (float)($j['z'] ?? 0);
    // trust client range roughly, just apply
    $newHp = max(0, (int)$t['hp'] - $damage);
    $killed = $newHp <= 0;
    $attackerName = (string)$u['username'];
    if ($killed) {
      db()->prepare(
        'UPDATE flex_world_players SET hp=100, pending_dmg=0, pending_attacker=NULL, last_killer=? WHERE user_id=?'
      )->execute([$attackerName, $targetId]);
      // XP 10000 for kill
      try {
        db()->prepare('UPDATE users SET xp = COALESCE(xp,0) + 10000 WHERE id=?')->execute([$uid]);
      } catch (Throwable $e) {}
      // notify target of death via pending
      db()->prepare(
        'UPDATE flex_world_players SET pending_dmg=?, pending_attacker=? WHERE user_id=?'
      )->execute([999, $attackerName, $targetId]); // 999 = death flag
      echo json_encode([
        'ok'=>true,'killed'=>true,'target_name'=>(string)$t['username'],'xp'=>10000
      ], JSON_UNESCAPED_UNICODE);
    } else {
      db()->prepare(
        'UPDATE flex_world_players SET hp=?, pending_dmg=pending_dmg+?, pending_attacker=? WHERE user_id=?'
      )->execute([$newHp, $damage, $attackerName, $targetId]);
      echo json_encode([
        'ok'=>true,'killed'=>false,'target_name'=>(string)$t['username'],'hp_left'=>$newHp
      ], JSON_UNESCAPED_UNICODE);
    }
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

try { db()->exec('DELETE FROM flex_world_players WHERE updated_at < (NOW() - INTERVAL 30 SECOND)'); } catch (Throwable $e) {}
try { db()->exec('DELETE FROM flex_world_queue WHERE joined_at < (NOW() - INTERVAL 2 HOUR)'); } catch (Throwable $e) {}

$staff = flex_world_is_staff($u);
if (!$staff) {
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM flex_world_players WHERE server_id=? AND user_id<>? AND updated_at > (NOW() - INTERVAL 20 SECOND)');
    $st->execute([$server, $uid]);
    if ((int)$st->fetchColumn() >= 10) {
      echo json_encode(['ok'=>false,'error'=>'full','message'=>'Сервер заполнен']);
      exit;
    }
  } catch (Throwable $e) {}
}

$av = flex_avatar_get($uid);
$x = max(-90, min(90, (float)($j['x'] ?? 0)));
$y = max(0, min(50, (float)($j['y'] ?? 1)));
$z = max(-90, min(90, (float)($j['z'] ?? 0)));
$yaw = (float)($j['yaw'] ?? 0);
$afk = !empty($j['afk']) ? 1 : 0;
$phone = !empty($j['phone']) ? 1 : 0;
$activity = mb_substr(trim((string)($j['activity'] ?? 'гуляет')), 0, 120);
$phoneUrl = mb_substr(trim((string)($j['phone_url'] ?? '')), 0, 500);
$phoneTitle = mb_substr(trim((string)($j['phone_title'] ?? '')), 0, 120);
if ($phoneUrl !== '' && !preg_match('#^https?://#i', $phoneUrl) && ($phoneUrl[0] ?? '') !== '/') $phoneUrl = '';
$crown = flex_world_has_crown($uid) ? 1 : 0;
$role = (string)($u['role'] ?? 'user');
$weapon = max(0, min(2, (int)($j['weapon'] ?? 0)));
$clientHp = max(0, min(100, (int)($j['hp'] ?? 100)));

// read pending damage for me before overwrite
$damageTaken = 0;
$attackerName = null;
$youDied = false;
try {
  $st = db()->prepare('SELECT pending_dmg, pending_attacker, hp FROM flex_world_players WHERE user_id=?');
  $st->execute([$uid]);
  $me = $st->fetch();
  if ($me) {
    $pd = (int)($me['pending_dmg'] ?? 0);
    if ($pd >= 999) {
      $youDied = true;
      $attackerName = (string)($me['pending_attacker'] ?? '');
      $damageTaken = 100;
    } elseif ($pd > 0) {
      $damageTaken = $pd;
      $attackerName = (string)($me['pending_attacker'] ?? '');
    }
  }
} catch (Throwable $e) {}

// after reading, clear pending
try {
  db()->prepare('UPDATE flex_world_players SET pending_dmg=0, pending_attacker=NULL WHERE user_id=?')->execute([$uid]);
} catch (Throwable $e) {}

// if died, reset hp
$storeHp = $youDied ? 100 : max(1, $clientHp - $damageTaken);
if ($youDied) $storeHp = 100;

try {
  db()->prepare(
    'INSERT INTO flex_world_players
      (server_id,user_id,username,x,y,z,yaw,is_afk,has_crown,merch_url,hat,head_color,torso_color,activity,phone_url,phone_title,phone_on,map_id,role,hp,weapon,pending_dmg,pending_attacker,updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,NOW())
     ON DUPLICATE KEY UPDATE
       username=VALUES(username),x=VALUES(x),y=VALUES(y),z=VALUES(z),yaw=VALUES(yaw),
       is_afk=VALUES(is_afk),has_crown=VALUES(has_crown),merch_url=VALUES(merch_url),hat=VALUES(hat),
       head_color=VALUES(head_color),torso_color=VALUES(torso_color),activity=VALUES(activity),
       phone_url=VALUES(phone_url),phone_title=VALUES(phone_title),phone_on=VALUES(phone_on),
       map_id=VALUES(map_id),role=VALUES(role),server_id=VALUES(server_id),
       hp=VALUES(hp),weapon=VALUES(weapon),updated_at=NOW()'
  )->execute([
    $server, $uid, (string)$u['username'], $x, $y, $z, $yaw, $afk, $crown,
    $av['merch_url'] ?: null, $av['hat'] ?? 'none', $av['head_color'] ?? null, $av['torso_color'] ?? null,
    $activity, $phoneUrl ?: null, $phoneTitle ?: null, $phone, $mapId, $role,
    $storeHp, $weapon
  ]);
} catch (Throwable $e) {
  // fallback without new columns
  try {
    db()->prepare(
      'INSERT INTO flex_world_players
        (server_id,user_id,username,x,y,z,yaw,is_afk,has_crown,merch_url,hat,head_color,torso_color,activity,phone_url,phone_title,phone_on,map_id,role,updated_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
       ON DUPLICATE KEY UPDATE
         username=VALUES(username),x=VALUES(x),y=VALUES(y),z=VALUES(z),yaw=VALUES(yaw),
         is_afk=VALUES(is_afk),has_crown=VALUES(has_crown),merch_url=VALUES(merch_url),hat=VALUES(hat),
         head_color=VALUES(head_color),torso_color=VALUES(torso_color),activity=VALUES(activity),
         phone_url=VALUES(phone_url),phone_title=VALUES(phone_title),phone_on=VALUES(phone_on),
         map_id=VALUES(map_id),role=VALUES(role),server_id=VALUES(server_id),updated_at=NOW()'
    )->execute([
      $server, $uid, (string)$u['username'], $x, $y, $z, $yaw, $afk, $crown,
      $av['merch_url'] ?: null, $av['hat'] ?? 'none', $av['head_color'] ?? null, $av['torso_color'] ?? null,
      $activity, $phoneUrl ?: null, $phoneTitle ?: null, $phone, $mapId, $role
    ]);
  } catch (Throwable $e2) {
    echo json_encode(['ok'=>false,'error'=>$e2->getMessage()]); exit;
  }
}

// XP payday
$xpGain = 0; $payday = null;
try {
  $slot30 = date('Y-m-d-H') . '-30';
  $slotHour = date('Y-m-d-H');
  $nowM = (int)date('i');
  db()->prepare('INSERT INTO flex_world_meta (user_id, ip_hash, play_seconds) VALUES (?,?,4) ON DUPLICATE KEY UPDATE play_seconds=play_seconds+4')
    ->execute([$uid, flex_world_ip_hash()]);
  $meta = db()->prepare('SELECT play_seconds, last_payday_slot, last_hour_xp FROM flex_world_meta WHERE user_id=?');
  $meta->execute([$uid]);
  $m = $meta->fetch() ?: [];
  
  // Daily login streak: 1000,2000,...10000
  try {
    $today = date('Y-m-d');
    $stD = db()->prepare('SELECT last_daily_date, daily_streak FROM flex_world_meta WHERE user_id=?');
    $stD->execute([$uid]);
    $dm = $stD->fetch() ?: ['last_daily_date'=>null,'daily_streak'=>0];
    if (($dm['last_daily_date'] ?? '') !== $today) {
      $prev = $dm['last_daily_date'] ?? null;
      $streak = (int)($dm['daily_streak'] ?? 0);
      if ($prev && $prev === date('Y-m-d', strtotime('-1 day'))) $streak = min(10, $streak + 1);
      else $streak = 1;
      $dailyXp = 1000 * $streak; // 1k..10k
      $xpGain += $dailyXp;
      db()->prepare('UPDATE flex_world_meta SET last_daily_date=?, daily_streak=? WHERE user_id=?')->execute([$today, $streak, $uid]);
      $outDaily = $dailyXp;
    }
  } catch (Throwable $e) {}

  if ($nowM >= 30 && ($m['last_payday_slot'] ?? '') !== $slot30) {
    $xpGain += 1000; $payday = 1000;
    db()->prepare('UPDATE flex_world_meta SET last_payday_slot=? WHERE user_id=?')->execute([$slot30, $uid]);
  }
  if ((int)($m['play_seconds'] ?? 0) >= 3600 && ($m['last_hour_xp'] ?? '') !== $slotHour) {
    $xpGain += 10000; $payday = 10000;
    db()->prepare('UPDATE flex_world_meta SET last_hour_xp=?, play_seconds=GREATEST(0,play_seconds-3600) WHERE user_id=?')->execute([$slotHour, $uid]);
  }
  if ($xpGain > 0) {
    try { db()->prepare('UPDATE users SET xp = COALESCE(xp,0) + ? WHERE id=?')->execute([$xpGain, $uid]); } catch (Throwable $e) {}
  }
} catch (Throwable $e) {}

$others = [];
try {
  $st = db()->prepare(
    'SELECT user_id, username, x, y, z, yaw, is_afk, has_crown, merch_url, hat, head_color, torso_color,
            activity, phone_url, phone_title, phone_on, role, hp, weapon
     FROM flex_world_players WHERE server_id=? AND user_id<>? AND updated_at > (NOW() - INTERVAL 18 SECOND) LIMIT 12'
  );
  $st->execute([$server, $uid]);
  $others = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  try {
    $st = db()->prepare(
      'SELECT user_id, username, x, y, z, yaw, is_afk, has_crown, merch_url, hat, head_color, torso_color,
              activity, phone_url, phone_title, phone_on, role
       FROM flex_world_players WHERE server_id=? AND user_id<>? AND updated_at > (NOW() - INTERVAL 18 SECOND) LIMIT 12'
    );
    $st->execute([$server, $uid]);
    $others = $st->fetchAll() ?: [];
  } catch (Throwable $e2) {}
}

echo json_encode([
  'ok' => true,
  'players' => $others,
  'online' => 1 + count($others),
  'xp' => $xpGain,
  'payday' => $payday,
  'daily_xp' => $outDaily ?? 0,
  'damage_taken' => $damageTaken > 0 && !$youDied ? $damageTaken : 0,
  'attacker_name' => $attackerName,
  'you_died' => $youDied,
  'hp' => $storeHp,
], JSON_UNESCAPED_UNICODE);
