<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/warnings.php';

$user = current_user();
$ok = false;
if ($user) {
  if (!empty($user['is_admin']) || !empty($user['is_moderator'])) $ok = true;
  if (function_exists('is_forum_moderator') && is_forum_moderator($user)) $ok = true;
  $role = strtolower((string)($user['role'] ?? ''));
  if (in_array($role, ['admin', 'moderator', 'mod'], true)) $ok = true;
}
if (!$ok) {
  http_response_code(403);
  exit('Модераторам только');
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $uid = (int)($_POST['user_id'] ?? 0);
  $reason = (string)($_POST['reason'] ?? '');
  $points = (int)($_POST['points'] ?? 1);
  $r = warnings_add($uid, (int)$user['id'], $reason, $points);
  $msg = $r['ok']
    ? "Выдано. За неделю: {$r['week_points']}/30" . ($r['banned'] ? ' · АВТОБЛОК' : '')
    : ('Ошибка: ' . ($r['error'] ?? ''));
}

$target = (int)($_GET['user_id'] ?? 0);
$list = $target > 0 ? warnings_list($target, 50) : [];
$week = $target > 0 ? warnings_count_week($target) : 0;

$pageTitle = 'Предупреждения';
if (is_file(__DIR__ . '/_layout_start.php')) require __DIR__ . '/_layout_start.php';
else {
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Warnings</title></head><body style="font-family:system-ui;background:#0f0f0f;color:#eee;padding:24px">';
}
?>
<h1>Предупреждения</h1>
<p style="color:#aaa">30 очков за неделю → автоблок 7 дней</p>
<?php if ($msg): ?><p style="background:#222;padding:12px;border-radius:8px"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
<form method="get" style="margin:12px 0;display:flex;gap:8px">
  <input type="number" name="user_id" value="<?= $target ?: '' ?>" placeholder="User ID" required style="padding:10px;border-radius:8px;border:1px solid #333;background:#111;color:#fff">
  <button style="padding:10px 14px;border-radius:8px;border:none;background:#3ea6ff;color:#000">Открыть</button>
</form>
<?php if ($target): ?>
<p>Неделя: <b><?= (int)$week ?></b>/30</p>
<form method="post" style="max-width:480px;background:#181818;padding:16px;border-radius:12px">
  <input type="hidden" name="user_id" value="<?= $target ?>">
  <input name="reason" required placeholder="Причина" style="width:100%;padding:10px;margin-bottom:8px;border-radius:8px;border:1px solid #333;background:#111;color:#fff">
  <input type="number" name="points" value="1" min="1" max="10" style="width:80px;padding:10px;border-radius:8px;border:1px solid #333;background:#111;color:#fff">
  <button style="margin-left:8px;padding:10px 16px;border-radius:20px;border:none;background:#ff0000;color:#fff">Выдать</button>
</form>
<ul style="margin-top:16px">
<?php foreach ($list as $w): ?>
  <li style="margin:8px 0;color:#ccc">#<?= (int)$w['id'] ?> · <?= htmlspecialchars($w['created_at']) ?> · +<?= (int)$w['points'] ?> · <?= htmlspecialchars($w['reason']) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php
if (is_file(__DIR__ . '/_layout_end.php')) require __DIR__ . '/_layout_end.php';
else echo '</body></html>';
