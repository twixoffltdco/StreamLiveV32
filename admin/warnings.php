<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/warnings.php';

$user = current_user();
if (!$user || (empty($user['is_admin']) && empty($user['role']) || (($user['role'] ?? '') !== 'admin' && empty($user['is_admin'])))) {
  // softer admin check
  if (!function_exists('require_admin')) {
    http_response_code(403);
    exit('Admin only');
  }
}
if (function_exists('require_admin')) {
  try { require_admin(); } catch (Throwable $e) {}
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $uid = (int)($_POST['user_id'] ?? 0);
  $reason = (string)($_POST['reason'] ?? '');
  $points = (int)($_POST['points'] ?? 1);
  $r = warnings_add($uid, (int)($user['id'] ?? 0), $reason, $points);
  $msg = $r['ok']
    ? "OK: user #{$uid}, за неделю {$r['week_points']} очков" . ($r['banned'] ? ' — АВТОБЛОК 7 дней' : '')
    : ('Ошибка: ' . ($r['error'] ?? ''));
}

$target = (int)($_GET['user_id'] ?? 0);
$list = $target > 0 ? warnings_list($target, 100) : [];
$week = $target > 0 ? warnings_count_week($target) : 0;

$pageTitle = 'Предупреждения';
require_once __DIR__ . '/_layout_start.php';
?>
<h1>Предупреждения</h1>
<p style="color:#aaa;margin-bottom:16px">30 очков за 7 дней → автоблок на 7 дней.</p>
<?php if ($msg): ?><div class="alert" style="padding:12px;background:#222;border-radius:8px;margin-bottom:12px"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<form method="get" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
  <input type="number" name="user_id" value="<?= $target ?: '' ?>" placeholder="User ID" required style="padding:10px;border-radius:8px;border:1px solid #333;background:#111;color:#fff">
  <button type="submit" style="padding:10px 16px;border-radius:8px;border:none;background:#3ea6ff;color:#000;font-weight:600">Найти</button>
</form>

<?php if ($target > 0): ?>
<p>За 7 дней: <b><?= (int)$week ?></b> / 30</p>
<form method="post" style="margin:16px 0;padding:16px;background:#181818;border-radius:12px;max-width:520px">
  <input type="hidden" name="user_id" value="<?= $target ?>">
  <label style="display:block;margin-bottom:6px;color:#aaa">Причина</label>
  <input name="reason" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #333;background:#111;color:#fff;margin-bottom:10px">
  <label style="display:block;margin-bottom:6px;color:#aaa">Очки (1–10)</label>
  <input type="number" name="points" value="1" min="1" max="10" style="width:100px;padding:10px;border-radius:8px;border:1px solid #333;background:#111;color:#fff;margin-bottom:12px">
  <div><button type="submit" style="padding:10px 18px;border-radius:20px;border:none;background:#ff0000;color:#fff;font-weight:600">Выдать предупреждение</button></div>
</form>
<table style="width:100%;border-collapse:collapse;font-size:14px">
  <thead><tr style="color:#aaa;text-align:left"><th>ID</th><th>Когда</th><th>Очки</th><th>Модер</th><th>Причина</th></tr></thead>
  <tbody>
  <?php foreach ($list as $w): ?>
    <tr style="border-top:1px solid #222">
      <td><?= (int)$w['id'] ?></td>
      <td><?= htmlspecialchars($w['created_at'] ?? '') ?></td>
      <td><?= (int)$w['points'] ?></td>
      <td><?= htmlspecialchars($w['mod_name'] ?? ('#'.$w['moderator_id'])) ?></td>
      <td><?= htmlspecialchars($w['reason'] ?? '') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
