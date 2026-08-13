<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mod_apply.php';
require_login();
mod_apply_ensure();

$me = current_user();
$uid = (int)($me['id'] ?? 0);
$pending = mod_apply_pending_for($uid);
$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('csrf_verify')) {
    try { csrf_verify(); } catch (Throwable $e) {}
  }
  $res = mod_apply_submit(
    $uid,
    (string)($_POST['reason'] ?? ''),
    (string)($_POST['experience'] ?? ''),
    (string)($_POST['contacts'] ?? '')
  );
  if ($res['ok']) {
    flash_set('success', 'Заявка отправлена. Администрация рассмотрит её.');
    redirect('/apply_moderator.php');
  }
  $flash['error'] = $res['error'] ?? 'Ошибка';
}

$pageTitle = 'Стать модератором';
require_once __DIR__ . '/includes/header.php';
$fg = function_exists('flash_get') ? flash_get() : [];
?>
<div class="container" style="max-width:640px;margin:24px auto;padding:0 16px">
  <h1 style="font-size:22px;font-weight:900">Стать модератором</h1>
  <p style="color:var(--text-dim);line-height:1.5">Оставьте заявку. Её рассмотрят администраторы. Спам и пустые заявки отклоняются.</p>

  <?php foreach (array_merge($fg, $flash) as $type => $msg): if (!is_string($msg) && !is_array($msg)) continue; ?>
    <p style="padding:10px;border-radius:10px;background:<?= $type==='error'?'#450a0a':'#052e16' ?>"><?= e(is_array($msg)?implode(', ',$msg):(string)$msg) ?></p>
  <?php endforeach; ?>

  <?php if (in_array(($me['role'] ?? ''), ['admin','moderator'], true)): ?>
    <p>Вы уже в команде модерации.</p>
  <?php elseif ($pending): ?>
    <div style="padding:14px;border-radius:12px;background:var(--card,#1e293b);border:1px solid rgba(167,139,250,.35)">
      <b>Заявка на рассмотрении</b>
      <p style="margin:8px 0 0;color:var(--text-dim);font-size:13px">Отправлена: <?= e($pending['created_at'] ?? '') ?></p>
    </div>
  <?php else: ?>
    <form method="post" class="form-card" style="margin-top:16px">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <label>Почему хотите стать модератором? *</label>
      <textarea name="reason" required minlength="20" rows="5" style="width:100%;padding:10px;border-radius:10px;box-sizing:border-box"></textarea>
      <label style="margin-top:10px;display:block">Опыт (форумы, стримы, модерация)</label>
      <textarea name="experience" rows="3" style="width:100%;padding:10px;border-radius:10px;box-sizing:border-box"></textarea>
      <label style="margin-top:10px;display:block">Контакт (TG / Discord)</label>
      <input name="contacts" maxlength="255" style="width:100%;padding:10px;border-radius:10px;box-sizing:border-box">
      <button type="submit" class="btn btn-primary" style="margin-top:14px">Отправить заявку</button>
    </form>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
