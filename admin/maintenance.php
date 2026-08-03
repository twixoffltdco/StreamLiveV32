<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
if (!function_exists('sl_maintenance_flag_path')) {
  require_once __DIR__ . '/../includes/prod_hardening.php';
}

$flag = sl_maintenance_flag_path();
$dir = dirname($flag);
if (!is_dir($dir)) @mkdir($dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $on = !empty($_POST['maintenance_on']);
  if ($on) {
    @file_put_contents($flag, date('c') . "\n", LOCK_EX);
    if (function_exists('set_setting')) @set_setting('maintenance_mode', '1');
    flash_set('success', 'Режим техработ ВКЛЮЧЁН (гости видят заглушку)');
  } else {
    if (is_file($flag)) @unlink($flag);
    if (function_exists('set_setting')) @set_setting('maintenance_mode', '0');
    flash_set('success', 'Режим техработ выключен');
  }
  redirect('/admin/maintenance.php');
}

$isOn = is_file($flag) || (function_exists('get_setting') && get_setting('maintenance_mode', '0') === '1');
require_once __DIR__ . '/_layout_start.php';
?>
<h2>Техработы</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:560px">
  Когда включено — обычные пользователи видят страницу «Технические работы».
  Админы и /admin работают как обычно. Файл-флаг: <code>storage/maintenance.on</code>
</p>
<form method="POST" class="form-card" style="max-width:420px">
  <?= csrf_field() ?>
  <label style="display:flex;align-items:center;gap:10px;margin:12px 0">
    <input type="checkbox" name="maintenance_on" value="1" <?= $isOn ? 'checked' : '' ?>>
    Включить режим техработ
  </label>
  <button class="btn btn-primary" type="submit">Сохранить</button>
</form>
<p style="margin-top:16px;font-size:13px">Статус сейчас: <b><?= $isOn ? 'ВКЛ' : 'выкл' ?></b>
 · <a href="/health.php" target="_blank">health.php</a></p>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
