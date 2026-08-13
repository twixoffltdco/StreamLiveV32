<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/moderator_auth.php';
require_once __DIR__ . '/../includes/paid_access.php';
require_moderator();
paid_ensure_schema();
$me = current_user();
$modId = (int)$me['id'];

function mod_promo_can_create(int $modId): array {
  try {
    $st = db()->prepare(
      'SELECT created_at FROM mod_promo_creates WHERE moderator_id = ? AND created_at >= (NOW() - INTERVAL 14 DAY) ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$modId]);
    $last = $st->fetchColumn();
    if ($last) {
      return ['ok' => false, 'next' => date('d.m.Y H:i', strtotime($last) + 14 * 86400)];
    }
  } catch (Throwable $e) {}
  return ['ok' => true, 'next' => null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $gate = mod_promo_can_create($modId);
    if (!$gate['ok']) {
      flash_set('error', 'Можно создать 1 промокод раз в 14 дней. Следующий с ' . $gate['next']);
    } else {
      $code = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_POST['code'] ?? '')));
      if ($code === '') $code = strtoupper(bin2hex(random_bytes(4)));
      $channelId = $_POST['channel_id'] !== '' ? (int)$_POST['channel_id'] : null;
      $max = max(0, (int)($_POST['max_uses'] ?? 10));
      try {
        db()->prepare(
          'INSERT INTO promo_codes (code, channel_id, created_by, max_uses) VALUES (?,?,?,?)'
        )->execute([$code, $channelId, $modId, $max]);
        $pid = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO mod_promo_creates (moderator_id, promo_id) VALUES (?,?)')->execute([$modId, $pid]);
        flash_set('success', 'Создан: ' . $code);
      } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
      }
    }
  } elseif ($action === 'revoke_act') {
    paid_revoke_activation((int)($_POST['act_id'] ?? 0), $modId);
    flash_set('success', 'Отозвано');
  }
  redirect('/moderator/promocodes');
}

$gate = mod_promo_can_create($modId);
$codes = db()->prepare('SELECT * FROM promo_codes WHERE created_by = ? ORDER BY id DESC LIMIT 50');
$codes->execute([$modId]);
$codes = $codes->fetchAll() ?: [];
$acts = db()->query(
  "SELECT a.*, p.code, u.username FROM promo_activations a
   JOIN promo_codes p ON p.id = a.promo_id
   JOIN users u ON u.id = a.user_id
   WHERE a.is_revoked = 0
   ORDER BY a.id DESC LIMIT 80"
)->fetchAll() ?: [];
$channels = db()->query("SELECT id, title FROM channels WHERE status='approved' ORDER BY title LIMIT 200")->fetchAll() ?: [];

$pageTitle = 'Промокоды';
require __DIR__ . '/../includes/header.php';
$flash = flash_get();
?>
<div class="container" style="max-width:800px;padding:24px 0">
  <h1>Промокоды (модератор)</h1>
  <p style="color:var(--text-dim);font-size:13px">Создание: <b>1 раз в 14 дней</b>. Отзыв активаций — без лимита.</p>
  <?php foreach ($flash as $t => $m): ?><div class="alert alert-<?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>

  <?php if ($gate['ok']): ?>
  <form method="post" class="form-card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Код<input name="code" placeholder="пусто = авто"></label>
    <label>Канал
      <select name="channel_id">
        <option value="">— все платные —</option>
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Макс. активаций<input type="number" name="max_uses" value="10" min="0"></label>
    <button class="btn btn-primary" type="submit">Создать</button>
  </form>
  <?php else: ?>
    <p class="alert alert-error">Следующее создание с <?= e($gate['next'] ?? '') ?></p>
  <?php endif; ?>

  <h3>Мои коды</h3>
  <ul><?php foreach ($codes as $p): ?>
    <li><code><?= e($p['code']) ?></code> · исп. <?= (int)$p['uses_count'] ?></li>
  <?php endforeach; ?></ul>

  <h3>Активации (отозвать)</h3>
  <?php foreach ($acts as $a): ?>
    <div style="display:flex;gap:10px;align-items:center;margin:6px 0">
      <span>@<?= e($a['username']) ?> · <?= e($a['code']) ?></span>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="revoke_act">
        <input type="hidden" name="act_id" value="<?= (int)$a['id'] ?>">
        <button class="btn btn-outline btn-sm" type="submit">Отозвать</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
