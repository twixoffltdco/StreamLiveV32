<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$pageTitle = 'Гео / VPN';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) { try { csrf_verify(); } catch (Throwable $e) {} }
  set_setting('geo_restrict_enabled', !empty($_POST['geo_restrict_enabled']) ? '1' : '0');
  set_setting('geo_vpn_block_enabled', !empty($_POST['geo_vpn_block_enabled']) ? '1' : '0');
  flash_set('success', 'Сохранено');
  redirect('/admin/geo.php');
}

$geo = get_setting('geo_restrict_enabled', '0') === '1';
$vpn = get_setting('geo_vpn_block_enabled', '0') === '1';
require_once __DIR__ . '/../includes/header.php';
$flash = function_exists('flash_get') ? flash_get() : [];
?>
<div class="container" style="max-width:640px;padding:24px 0">
  <h1>Гео и анти-VPN</h1>
  <?php foreach ($flash as $t => $m): ?><div class="alert alert-<?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>
  <form method="post" class="form-card">
    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
    <label style="display:flex;gap:10px;align-items:center;margin:12px 0">
      <input type="checkbox" name="geo_restrict_enabled" value="1" <?= $geo ? 'checked' : '' ?>>
      <span>Геоблок: только страны СНГ (остальным 403)</span>
    </label>
    <label style="display:flex;gap:10px;align-items:center;margin:12px 0">
      <input type="checkbox" name="geo_vpn_block_enabled" value="1" <?= $vpn ? 'checked' : '' ?>>
      <span>Блок VPN / прокси / датацентровых IP для обычных пользователей</span>
    </label>
    <p style="font-size:13px;color:var(--text-dim);line-height:1.5">
      Админы и модераторы <b>не блокируются</b> — могут заходить с VPN.
      Определение через ip-api (proxy/hosting), кэш 7 дней.
    </p>
    <button class="btn btn-primary" type="submit">Сохранить</button>
  </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
