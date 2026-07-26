<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
require_once __DIR__ . '/../includes/moderation_limits.php';
moderation_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $reqId = (int)($_POST['id'] ?? 0);
  $stmt = db()->prepare('SELECT * FROM moderation_requests WHERE id = ?');
  $stmt->execute([$reqId]);
  $req = $stmt->fetch();

  if (!$req || $req['status'] !== 'pending') {
    flash_set('error', 'Запрос уже обработан или не найден');
    redirect('/moderator/mod_requests.php');
  }

  // Инициатор не может подтвердить свой же запрос — ровно то же правило самомодерации,
  // только применительно к запросам на роль, а не к контенту.
  if ((int)$req['requested_by'] === (int)$__user['id']) {
    flash_set('error', 'Вы не можете подтвердить свой же запрос — дождитесь другого модератора или администратора.');
    redirect('/moderator/mod_requests.php');
  }

  if (($_POST['action'] ?? '') === 'approve') {
    $stmtT = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmtT->execute([$req['target_user_id']]);
    $targetUser = $stmtT->fetch();

    if ($targetUser) {
      $__cooldownLeft = role_change_cooldown_remaining_hours($targetUser);
      if ($__cooldownLeft !== null) {
        flash_set('error', "Роль этого пользователя менялась недавно — подтвердить можно через {$__cooldownLeft} ч.");
        redirect('/moderator/mod_requests.php');
      }
      $newRole = $req['action_type'] === 'grant_moderator' ? 'moderator' : 'user';
      db()->prepare('UPDATE users SET role = ?, role_changed_at = NOW() WHERE id = ?')->execute([$newRole, $req['target_user_id']]);
    }
    db()->prepare("UPDATE moderation_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
      ->execute([$__user['id'], $reqId]);
    flash_set('success', 'Подтверждено и применено');
  } elseif (($_POST['action'] ?? '') === 'cancel') {
    db()->prepare("UPDATE moderation_requests SET status='cancelled', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
      ->execute([$__user['id'], $reqId]);
    flash_set('success', 'Запрос отменён, роль не менялась');
  }
  redirect('/moderator/mod_requests.php');
}

$requests = db()->query(
  "SELECT mr.*, tu.username AS target_username, ru.username AS requester_username
   FROM moderation_requests mr
   JOIN users tu ON tu.id = mr.target_user_id
   JOIN users ru ON ru.id = mr.requested_by
   WHERE mr.status = 'pending' ORDER BY mr.created_at ASC"
)->fetchAll();

$actionLabels = [
  'grant_moderator' => 'Выдать роль модератора',
  'revoke_moderator' => 'Снять роль модератора',
  'ban' => 'Заблокировать',
  'unban' => 'Разблокировать',
];
?>
<h2>Запросы на подтверждение (<?= count($requests) ?>)</h2>
<p style="color:var(--text-dim);font-size:13px;max-width:600px">
  Назначение и снятие роли модератора требует подтверждения ДРУГИМ модератором или
  администратором — тот, кто создал запрос, подтвердить его сам не может.
</p>

<?php if (!$requests): ?>
  <div class="empty-state">Пусто — нет ожидающих запросов</div>
<?php endif; ?>

<?php foreach ($requests as $r): ?>
  <div class="form-card form-wide" style="margin-bottom:12px">
    <b><?= e($actionLabels[$r['action_type']] ?? $r['action_type']) ?></b> —
    пользователь <b>@<?= e($r['target_username']) ?></b>
    <div style="font-size:12px;color:var(--text-dim);margin:4px 0">
      Запросил: @<?= e($r['requester_username']) ?> · <?= e($r['created_at']) ?>
      <?php if ($r['reason']): ?><br>Причина: <?= e($r['reason']) ?><?php endif; ?>
    </div>
    <?php if ((int)$r['requested_by'] === (int)$__user['id']): ?>
      <p style="color:var(--text-dim);font-size:12px;font-style:italic">Это ваш запрос — ждём подтверждения от другого модератора/администратора.</p>
    <?php else: ?>
      <div style="display:flex;gap:8px">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('Вы точно хотите применить это решение?')">Подтвердить</button></form>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-outline btn-sm" type="submit">Отменить</button></form>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
