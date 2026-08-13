<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/paid_access.php';
require_admin();
paid_ensure_schema();
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_POST['code'] ?? '')));
    if ($code === '') $code = strtoupper(bin2hex(random_bytes(4)));
    $channelId = $_POST['channel_id'] !== '' ? (int)$_POST['channel_id'] : null;
    $max = max(0, (int)($_POST['max_uses'] ?? 0));
    $note = trim((string)($_POST['note'] ?? ''));
    $exp = trim((string)($_POST['expires_at'] ?? ''));
    $expSql = $exp !== '' ? date('Y-m-d H:i:s', strtotime($exp)) : null;
    try {
      db()->prepare(
        'INSERT INTO promo_codes (code, channel_id, created_by, max_uses, note, expires_at) VALUES (?,?,?,?,?,?)'
      )->execute([$code, $channelId, (int)$me['id'], $max, $note ?: null, $expSql]);
      flash_set('success', 'Код: ' . $code);
    } catch (Throwable $e) {
      flash_set('error', $e->getMessage());
    }
  } elseif ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    db()->prepare('UPDATE promo_codes SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
  } elseif ($action === 'revoke_act') {
    paid_revoke_activation((int)($_POST['act_id'] ?? 0), (int)$me['id']);
    flash_set('success', 'Активация отозвана — доступ закрыт');
  }
  redirect('/admin/promocodes');
}

$codes = db()->query(
  'SELECT p.*, u.username AS creator FROM promo_codes p LEFT JOIN users u ON u.id = p.created_by ORDER BY p.id DESC LIMIT 200'
)->fetchAll() ?: [];
$acts = db()->query(
  "SELECT a.*, p.code, u.username FROM promo_activations a
   JOIN promo_codes p ON p.id = a.promo_id
   JOIN users u ON u.id = a.user_id
   ORDER BY a.id DESC LIMIT 100"
)->fetchAll() ?: [];
$channels = db()->query("SELECT id, title FROM channels ORDER BY title LIMIT 300")->fetchAll() ?: [];

$pageTitle = 'Промокоды';
require __DIR__ . '/../includes/header.php';
$flash = flash_get();
?>
<div class="container" style="max-width:960px;padding:24px 0">
  <h1>Промокоды</h1>
  <?php foreach ($flash as $t => $m): ?><div class="alert alert-<?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?>

  <form method="post" class="form-card" style="margin-bottom:24px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Код (пусто = сгенерировать)<input name="code" maxlength="64" placeholder="VIP-2026"></label>
    <label>Канал (пусто = любой платный)
      <select name="channel_id">
        <option value="">— все платные —</option>
        <?php foreach ($channels as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Макс. активаций (0 = ∞)<input type="number" name="max_uses" value="0" min="0"></label>
    <label>Истекает<input type="datetime-local" name="expires_at"></label>
    <label>Заметка<input name="note" maxlength="255"></label>
    <button class="btn btn-primary" type="submit">Создать</button>
  </form>

  <h3>Коды</h3>
  <table class="admin-table" style="width:100%">
    <tr><th>Код</th><th>Канал</th><th>Исп.</th><th>Кто</th><th></th></tr>
    <?php foreach ($codes as $p): ?>
    <tr>
      <td><code><?= e($p['code']) ?></code> <?= empty($p['is_active']) ? '(выкл)' : '' ?></td>
      <td><?= $p['channel_id'] ? (int)$p['channel_id'] : 'все' ?></td>
      <td><?= (int)$p['uses_count'] ?><?= (int)$p['max_uses'] > 0 ? ' / ' . (int)$p['max_uses'] : '' ?></td>
      <td>@<?= e($p['creator'] ?? '') ?></td>
      <td>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit"><?= !empty($p['is_active']) ? 'Выкл' : 'Вкл' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>

  <h3 style="margin-top:28px">Активации</h3>
  <table class="admin-table" style="width:100%">
    <tr><th>Юзер</th><th>Код</th><th>Канал</th><th>Когда</th><th></th></tr>
    <?php foreach ($acts as $a): ?>
    <tr style="<?= !empty($a['is_revoked']) ? 'opacity:.5' : '' ?>">
      <td>@<?= e($a['username']) ?></td>
      <td><?= e($a['code']) ?></td>
      <td><?= $a['channel_id'] ? (int)$a['channel_id'] : '—' ?></td>
      <td><?= e($a['activated_at']) ?><?= !empty($a['is_revoked']) ? ' · отозван' : '' ?></td>
      <td>
        <?php if (empty($a['is_revoked'])): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Отозвать доступ?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="revoke_act">
          <input type="hidden" name="act_id" value="<?= (int)$a['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit">Отозвать</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
