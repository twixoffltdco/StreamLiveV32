<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)$_POST['user_id'];
  if ($_POST['action'] === 'reset_password') {
    $tempPassword = bin2hex(random_bytes(5)); // временный пароль, показываем один раз админу
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, password_reset_by_admin_at = NOW() WHERE id = ?')
      ->execute([$hash, (int)$_POST['user_id']]);
    flash_set('success', 'Временный пароль: ' . $tempPassword . ' — передайте его пользователю, он сменит его при первом входе (после 2FA, если включена)');
  } elseif ($_POST['action'] === 'ban') {
    db()->prepare('UPDATE users SET is_banned = 1 WHERE id = ?')->execute([$id]);
  } elseif ($_POST['action'] === 'unban') {
    db()->prepare('UPDATE users SET is_banned = 0 WHERE id = ?')->execute([$id]);
  } elseif ($_POST['action'] === 'role') {
    $role = in_array($_POST['role'], ['user','moderator','admin'], true) ? $_POST['role'] : 'user';
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
  } elseif ($_POST['action'] === 'reset_2fa') {
    db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')->execute([$id]);
    flash_set('success', 'Пользователь сбросит и заново настроит 2FA при следующем входе');
  } elseif ($_POST['action'] === 'verify') {
    db()->prepare('UPDATE users SET is_verified = 1 WHERE id = ?')->execute([$id]);
  } elseif ($_POST['action'] === 'unverify') {
    db()->prepare('UPDATE users SET is_verified = 0 WHERE id = ?')->execute([$id]);
  }
  redirect('/admin/users.php');
}
$users = db()->query('SELECT id, email, username, role, is_banned, totp_enabled, is_verified, created_at FROM users ORDER BY id DESC')->fetchAll();
?>
<h2>Пользователи</h2>
<table class="admin-table">
  <thead><tr><th>Логин</th><th>Email</th><th>Роль</th><th>Статус</th><th>2FA</th><th>Галочка</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['username']) ?><?php if ($u['is_verified']): ?> <span class="verify-badge" title="Подтверждённый аккаунт">✓</span><?php endif; ?></td>
      <td><?= e($u['email'] ?: '—') ?></td>
      <td>
        <form method="POST" onchange="this.submit()">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="role">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <select name="role">
            <option value="user" <?= $u['role']==='user'?'selected':'' ?>>user</option>
            <option value="moderator" <?= $u['role']==='moderator'?'selected':'' ?>>moderator</option>
            <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>admin</option>
          </select>
        </form>
      </td>
      <td><?= $u['is_banned'] ? '<span class="status-pill status-rejected">забанен</span>' : '<span class="status-pill status-approved">активен</span>' ?></td>
      <td><?= $u['totp_enabled'] ? '<span class="status-pill status-approved">включена</span>' : '<span class="status-pill status-pending">не настроена</span>' ?></td>
      <td>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <?php if ($u['is_verified']): ?>
            <input type="hidden" name="action" value="unverify">
            <button class="btn btn-outline btn-sm" type="submit">Убрать ✓</button>
          <?php else: ?>
            <input type="hidden" name="action" value="verify">
            <button class="btn btn-primary btn-sm" type="submit">Выдать ✓</button>
          <?php endif; ?>
        </form>
      </td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <?php if ($u['is_banned']): ?>
            <input type="hidden" name="action" value="unban">
            <button class="btn btn-ok btn-sm" type="submit">Разбанить</button>
          <?php else: ?>
            <input type="hidden" name="action" value="ban">
            <button class="btn btn-danger btn-sm" type="submit">Забанить</button>
          <?php endif; ?>
        </form>
        <?php if ($u['totp_enabled']): ?>
        <form method="POST" onsubmit="return confirm('Сбросить 2FA этому пользователю? Понадобится при потере телефона.')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset_2fa">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit">Сбросить 2FA</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
