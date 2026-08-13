<?php
if (!function_exists('brands_list_for_user')) {
  if (is_file(__DIR__ . '/brands.php')) require_once __DIR__ . '/brands.php';
}
if (!function_exists('brands_real_user_id')) return;
$__bid = brands_real_user_id();
if ($__bid <= 0) return;
try { brands_ensure_schema(); } catch (Throwable $e) { return; }
$__brands = brands_list_for_user($__bid);
$__act = brands_active_id();
?>
<div class="brand-switch-widget" style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap">
  <a href="/brands/" style="font-size:12px;font-weight:700;color:#00a2ff;text-decoration:none">Бренды</a>
  <?php if ($__act): ?>
    <a href="/brands/switch.php?to=0&redirect=<?= rawurlencode($_SERVER['REQUEST_URI'] ?? '/') ?>" style="font-size:11px;padding:4px 8px;border-radius:8px;background:#334155;color:#fff;text-decoration:none">Личный</a>
  <?php endif; ?>
  <?php foreach (array_slice($__brands, 0, 5) as $__b): ?>
    <a href="/brands/switch.php?to=<?= (int)$__b['id'] ?>&redirect=<?= rawurlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
       style="font-size:11px;padding:4px 8px;border-radius:8px;background:<?= $__act===(int)$__b['id']?'#00a2ff':'#1e293b' ?>;color:#fff;text-decoration:none">@<?= htmlspecialchars($__b['username'], ENT_QUOTES) ?></a>
  <?php endforeach; ?>
</div>
