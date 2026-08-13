<?php
require_once __DIR__ . '/../includes/auth.php';
if (is_file(__DIR__ . '/../includes/csrf_compat.php')) require_once __DIR__ . '/../includes/csrf_compat.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/battle_pass.php';
require_admin();
bp_ensure_schema();
$codes = bp_task_codes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'save_season') {
      $id = (int)($_POST['id'] ?? 0);
      $title = trim((string)($_POST['title'] ?? ''));
      $desc = trim((string)($_POST['description'] ?? ''));
      $active = !empty($_POST['is_active']) ? 1 : 0;
      if ($title === '') throw new RuntimeException('Укажите название');
      if ($active) db()->exec('UPDATE bp_seasons SET is_active=0');
      if ($id > 0) {
        db()->prepare('UPDATE bp_seasons SET title=?, description=?, is_active=? WHERE id=?')
          ->execute([$title, $desc, $active, $id]);
      } else {
        db()->prepare('INSERT INTO bp_seasons (title, description, is_active) VALUES (?,?,?)')
          ->execute([$title, $desc, $active]);
      }
      flash_set('success', 'Сезон сохранён');
    }
    if ($action === 'add_level') {
      $sid = (int)($_POST['season_id'] ?? 0);
      $num = (int)($_POST['level_num'] ?? 1);
      $xp = max(1, (int)($_POST['xp_required'] ?? 100));
      $rtype = in_array($_POST['reward_type'] ?? '', ['promo','xp','prefix','text'], true) ? $_POST['reward_type'] : 'text';
      $rval = trim((string)($_POST['reward_value'] ?? ''));
      $rlabel = trim((string)($_POST['reward_label'] ?? ''));
      db()->prepare(
        'INSERT INTO bp_levels (season_id, level_num, xp_required, reward_type, reward_value, reward_label)
         VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE xp_required=VALUES(xp_required), reward_type=VALUES(reward_type),
         reward_value=VALUES(reward_value), reward_label=VALUES(reward_label)'
      )->execute([$sid, $num, $xp, $rtype, $rval, $rlabel]);
      flash_set('success', 'Уровень сохранён');
    }
    if ($action === 'add_task') {
      $sid = (int)($_POST['season_id'] ?? 0);
      $code = (string)($_POST['code'] ?? '');
      if (!isset($codes[$code])) throw new RuntimeException('Неизвестный код задания');
      $title = trim((string)($_POST['title'] ?? $codes[$code]));
      $xp = max(1, (int)($_POST['xp_reward'] ?? 50));
      $target = max(1, (int)($_POST['target_count'] ?? 1));
      db()->prepare(
        'INSERT INTO bp_tasks (season_id, code, title, xp_reward, target_count, is_active) VALUES (?,?,?,?,?,1)'
      )->execute([$sid, $code, $title, $xp, $target]);
      flash_set('success', 'Задание добавлено');
    }
    if ($action === 'del_task') {
      db()->prepare('DELETE FROM bp_tasks WHERE id=?')->execute([(int)$_POST['id']]);
      flash_set('success', 'Удалено');
    }
    if ($action === 'del_level') {
      db()->prepare('DELETE FROM bp_levels WHERE id=?')->execute([(int)$_POST['id']]);
      flash_set('success', 'Уровень удалён');
    }
  } catch (Throwable $e) {
    flash_set('error', $e->getMessage());
  }
  redirect('/admin/battle_pass.php');
}

$seasons = db()->query('SELECT * FROM bp_seasons ORDER BY id DESC')->fetchAll() ?: [];
$sid = (int)($_GET['season_id'] ?? ($seasons[0]['id'] ?? 0));
$levels = [];
$tasks = [];
if ($sid > 0) {
  $st = db()->prepare('SELECT * FROM bp_levels WHERE season_id=? ORDER BY level_num');
  $st->execute([$sid]);
  $levels = $st->fetchAll() ?: [];
  $st = db()->prepare('SELECT * FROM bp_tasks WHERE season_id=? ORDER BY id');
  $st->execute([$sid]);
  $tasks = $st->fetchAll() ?: [];
}
$pageTitle = 'Battle Pass';
require __DIR__ . '/_layout_start.php';
?>
<h1>Battle Pass</h1>
<p class="muted">Сезон → уровни (награды: промокод на <b>3 дня</b> платного контента / XP / префикс) → задания с платформы.</p>

<div class="card" style="padding:16px;margin-bottom:16px">
  <h3>Сезон</h3>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_season">
    <input type="hidden" name="id" value="<?= $sid ?>">
    <?php
      $cur = null;
      foreach ($seasons as $s) if ((int)$s['id'] === $sid) $cur = $s;
    ?>
    <p><label>Название<br><input name="title" value="<?= e($cur['title'] ?? '') ?>" required style="width:100%;max-width:420px"></label></p>
    <p><label>Описание<br><textarea name="description" rows="2" style="width:100%;max-width:420px"><?= e($cur['description'] ?? '') ?></textarea></label></p>
    <p><label><input type="checkbox" name="is_active" value="1" <?= !empty($cur['is_active']) ? 'checked' : '' ?>> Активный сезон</label></p>
    <button class="btn btn-primary" type="submit">Сохранить сезон</button>
  </form>
  <?php if ($seasons): ?>
  <p style="margin-top:12px">Сезоны:
    <?php foreach ($seasons as $s): ?>
      <a href="?season_id=<?= (int)$s['id'] ?>"><?= e($s['title']) ?><?= !empty($s['is_active']) ? ' ●' : '' ?></a>
    <?php endforeach; ?>
  </p>
  <?php endif; ?>
</div>

<?php if ($sid > 0): ?>
<div class="card" style="padding:16px;margin-bottom:16px">
  <h3>Уровни / награды</h3>
  <form method="post" style="display:grid;gap:8px;max-width:520px">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_level">
    <input type="hidden" name="season_id" value="<?= $sid ?>">
    <label>№ уровня <input type="number" name="level_num" value="<?= count($levels)+1 ?>" min="1"></label>
    <label>XP до уровня <input type="number" name="xp_required" value="100" min="1"></label>
    <label>Тип награды
      <select name="reward_type">
        <option value="promo">Промокод (3 дня платного доступа)</option>
        <option value="xp">XP платформы</option>
        <option value="prefix">ID префикса</option>
        <option value="text">Текст / косметика</option>
      </select>
    </label>
    <label>Значение (код промо / число XP / id префикса)<br><input name="reward_value" style="width:100%"></label>
    <label>Подпись награды<br><input name="reward_label" style="width:100%" placeholder="Напр. Игровая тусовка"></label>
    <button class="btn btn-primary" type="submit">Добавить уровень</button>
  </form>
  <table style="width:100%;margin-top:12px;font-size:13px">
    <tr><th>#</th><th>XP</th><th>Награда</th><th></th></tr>
    <?php foreach ($levels as $L): ?>
    <tr>
      <td><?= (int)$L['level_num'] ?></td>
      <td><?= (int)$L['xp_required'] ?></td>
      <td><?= e($L['reward_type']) ?>: <?= e($L['reward_label'] ?: $L['reward_value']) ?></td>
      <td>
        <form method="post" style="display:inline" onsubmit="return confirm('Удалить?')">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="del_level">
          <input type="hidden" name="id" value="<?= (int)$L['id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm">×</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card" style="padding:16px">
  <h3>Задания</h3>
  <form method="post" style="display:grid;gap:8px;max-width:520px">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="add_task">
    <input type="hidden" name="season_id" value="<?= $sid ?>">
    <label>Тип
      <select name="code">
        <?php foreach ($codes as $c => $lab): ?>
          <option value="<?= e($c) ?>"><?= e($lab) ?> (<?= e($c) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Название <input name="title" style="width:100%" placeholder="Как видит игрок"></label>
    <label>XP за выполнение <input type="number" name="xp_reward" value="50" min="1"></label>
    <label>Сколько раз <input type="number" name="target_count" value="1" min="1"></label>
    <button class="btn btn-primary" type="submit">Добавить задание</button>
  </form>
  <ul style="margin-top:12px">
    <?php foreach ($tasks as $t): ?>
      <li><?= e($t['title']) ?> · <?= e($t['code']) ?> · +<?= (int)$t['xp_reward'] ?> XP ×<?= (int)$t['target_count'] ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Удалить?')">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="del_task">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm">×</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_layout_end.php'; ?>
