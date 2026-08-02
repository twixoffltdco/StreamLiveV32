<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/moderator_auth.php';
require_once __DIR__ . '/../includes/user_display.php';
require_moderator();
user_display_ensure_schema();

/** Таблица логов действий мода по префиксам (1 действие / 24ч на юзера) */
function mod_prefix_log_ensure(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS mod_prefix_actions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        moderator_id INT UNSIGNED NOT NULL,
        target_user_id INT UNSIGNED NOT NULL,
        action VARCHAR(16) NOT NULL,
        prefix_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mod_time (moderator_id, created_at),
        INDEX idx_target_time (target_user_id, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function mod_prefix_can_act(int $moderatorId, int $targetUserId): array {
  mod_prefix_log_ensure();
  try {
    $st = db()->prepare(
      "SELECT created_at FROM mod_prefix_actions
       WHERE moderator_id = ? AND target_user_id = ?
         AND created_at >= (NOW() - INTERVAL 24 HOUR)
       ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$moderatorId, $targetUserId]);
    $last = $st->fetchColumn();
    if ($last) {
      $next = strtotime($last) + 86400;
      return ['ok' => false, 'next' => date('d.m.Y H:i', $next)];
    }
  } catch (Throwable $e) {}
  return ['ok' => true, 'next' => null];
}

function mod_prefix_log_act(int $moderatorId, int $targetUserId, string $action, int $prefixId = 0): void {
  mod_prefix_log_ensure();
  try {
    db()->prepare(
      'INSERT INTO mod_prefix_actions (moderator_id, target_user_id, action, prefix_id) VALUES (?,?,?,?)'
    )->execute([$moderatorId, $targetUserId, $action, $prefixId > 0 ? $prefixId : null]);
  } catch (Throwable $e) {}
}

$mod = current_user();
$modId = (int)($mod['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $uid = (int)($_POST['user_id'] ?? 0);
  $action = (string)($_POST['action'] ?? '');
  $pid = (int)($_POST['prefix_id'] ?? 0);

  if ($uid <= 0 || !in_array($action, ['assign', 'remove'], true)) {
    flash_set('error', 'Некорректные данные');
    redirect('/moderator/prefixes.php');
  }

  $gate = mod_prefix_can_act($modId, $uid);
  if (!$gate['ok']) {
    flash_set('error', 'На этого пользователя уже было действие за 24 часа. Следующее с ' . ($gate['next'] ?? '—'));
    redirect('/moderator/prefixes.php');
  }

  if ($action === 'assign' && $pid > 0) {
    // Личный префикс чужого/свой custom — нельзя выдавать через мод-панель
    try {
      $chk = db()->prepare('SELECT is_personal, owner_user_id FROM user_prefixes WHERE id = ?');
      $chk->execute([$pid]);
      $row = $chk->fetch();
      if ($row && (!empty($row['is_personal']) || (int)($row['owner_user_id'] ?? 0) > 0)) {
        flash_set('error', 'Это личный префикс пользователя — его нельзя выдавать другим');
        redirect('/moderator/prefixes.php');
      }
    } catch (Throwable $e) {}

    $customId = 0;
    try {
      $st = db()->prepare('SELECT custom_prefix_id FROM users WHERE id = ?');
      $st->execute([$uid]);
      $customId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {}
    $ids = [$pid];
    if ($customId > 0 && $customId !== $pid) $ids[] = $customId;
    user_set_prefixes($uid, array_slice($ids, 0, 3));
    mod_prefix_log_act($modId, $uid, 'assign', $pid);
    flash_set('success', 'Префикс выдан (следующее действие на этого юзера через 24ч)');
  } elseif ($action === 'remove') {
    $customId = 0;
    try {
      $st = db()->prepare('SELECT custom_prefix_id FROM users WHERE id = ?');
      $st->execute([$uid]);
      $customId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {}
    $keep = [];
    if ($customId > 0) $keep[] = $customId;
    if ($pid > 0) {
      try {
        $map = db()->prepare('SELECT prefix_id FROM user_prefix_map WHERE user_id = ?');
        $map->execute([$uid]);
        foreach ($map->fetchAll() as $r) {
          $id = (int)$r['prefix_id'];
          if ($id === $pid || $id === $customId) continue;
          $keep[] = $id;
        }
      } catch (Throwable $e) {}
    }
    user_set_prefixes($uid, array_values(array_unique(array_slice($keep, 0, 3))));
    mod_prefix_log_act($modId, $uid, 'remove', $pid);
    flash_set('success', 'Префикс снят (следующее действие на этого юзера через 24ч)');
  }
  redirect('/moderator/prefixes.php');
}

$prefixes = [];
$users = [];
try {
  $prefixes = function_exists('user_prefixes_system_list') ? user_prefixes_system_list(true) : (db()->query('SELECT * FROM user_prefixes WHERE is_active = 1 AND (is_personal = 0 OR is_personal IS NULL) ORDER BY sort_order, id')->fetchAll() ?: []);
} catch (Throwable $e) {}
try {
  $users = db()->query('SELECT id, username, prefix_id FROM users ORDER BY id DESC LIMIT 150')->fetchAll() ?: [];
} catch (Throwable $e) {}

$pageTitle = 'Префиксы';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="max-width:800px;margin:24px auto">
  <p style="color:var(--text-dim);font-size:13px;margin:8px 0 12px">Выдаются только <b>системные</b> префиксы. Личные (созданные пользователем для себя) здесь не показываются и не выдаются другим.</p>
  <h1>Префиксы (модерация)</h1>
  <p style="color:var(--text-dim);font-size:13px">
    Одно действие на пользователя раз в <b>24 часа</b> (выдать <i>или</i> снять).
    Свой префикс пользователя создаётся только в профиле (раз в 30 дней) и здесь не удаляется при «снять всё официальное».
  </p>

  <div class="card" style="padding:16px;margin-bottom:16px">
    <h3>Выдать</h3>
    <form method="post" style="display:grid;gap:10px;max-width:420px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign">
      <label>Пользователь
        <select name="user_id" required style="width:100%;padding:8px">
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>">@<?= e($u['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Префикс
        <select name="prefix_id" required style="width:100%;padding:8px">
          <?php foreach ($prefixes as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-primary" type="submit">Выдать</button>
    </form>
  </div>

  <div class="card" style="padding:16px">
    <h3>Снять</h3>
    <form method="post" style="display:grid;gap:10px;max-width:420px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remove">
      <label>Пользователь
        <select name="user_id" required style="width:100%;padding:8px">
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>">@<?= e($u['username']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Префикс
        <select name="prefix_id" style="width:100%;padding:8px">
          <option value="0">— все официальные (свой оставить) —</option>
          <?php foreach ($prefixes as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e($p['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn btn-danger" type="submit">Снять</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
