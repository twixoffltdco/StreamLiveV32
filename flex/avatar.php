<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/flex_world.php';
$u = current_user();
if (!$u) { redirect('/auth/login.php?redirect=' . urlencode('/flex/avatar.php')); }
flex_world_ensure();
$av = flex_avatar_get((int)$u['id']);
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_check')) csrf_check();
  flex_avatar_save((int)$u['id'], $_POST);
  $av = flex_avatar_get((int)$u['id']);
  $msg = 'Сохранено';
}
$pageTitle = 'Flex · Персонаж';
require dirname(__DIR__) . '/includes/header.php';
?>
<div class="container" style="max-width:560px;padding:20px 16px">
  <h1>Персонаж Flex</h1>
  <?php if ($msg): ?><p style="color:#22c55e"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
  <form method="post" class="form-card" style="padding:16px">
    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
    <label>Голова <input type="color" name="head_color" value="<?= htmlspecialchars($av['head_color']) ?>"></label><br><br>
    <label>Торс <input type="color" name="torso_color" value="<?= htmlspecialchars($av['torso_color']) ?>"></label><br><br>
    <label>Руки <input type="color" name="arms_color" value="<?= htmlspecialchars($av['arms_color'] ?? '#f5d0c5') ?>"></label><br><br>
    <label>Ноги <input type="color" name="legs_color" value="<?= htmlspecialchars($av['legs_color'] ?? '#1e3a5f') ?>"></label><br><br>
    <label>Шляпа
      <select name="hat">
        <?php foreach (['none'=>'Нет','cap'=>'Кепка','crown_hat'=>'Шапка','top'=>'Цилиндр'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($av['hat']??'')===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </label><br><br>
    <label>Аксессуар
      <select name="accessory">
        <?php foreach (['none'=>'Нет','glasses'=>'Очки','scarf'=>'Шарф','backpack'=>'Рюкзак'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($av['accessory']??'')===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </label><br><br>
    <label>Мерч PNG (прямая ссылка, прозрачный фон)
      <input type="url" name="merch_url" value="<?= htmlspecialchars((string)($av['merch_url'] ?? '')) ?>" placeholder="https://.../merch.png" style="width:100%">
    </label>
    <p style="font-size:12px;color:#888">Нужен CORS с хоста картинки, иначе мерч не подтянется.</p>
    <button type="submit" class="btn btn-primary">Сохранить</button>
    <a class="btn" href="/flex/world.php">В мир</a>
  </form>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
