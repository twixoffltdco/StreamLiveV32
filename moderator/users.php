<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)$_POST['user_id'];

  $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
  $stmt->execute([$id]);
  $targetRole = $stmt->fetchColumn();

  // Модератор не трогает админов вообще (ни роль, ни галочку) — это не его уровень доступа,
  // а полноценное управление ролью admin остаётся только в /admin/users.php.
  if ($targetRole === 'admin') {
    flash_set('error', 'Администраторов может менять только другой администратор');
    redirect('/moderator/users.php');
  }

  if ($_POST['action'] === 'role') {
    // Модератору доступно только user <-> moderator, admin выдать/снять он не может ни при каких условиях.
    $role = $_POST['role'] === 'moderator' ? 'moderator' : 'user';
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    flash_set('success', 'Роль обновлена');
  } elseif ($_POST['action'] === 'verify') {
    db()->prepare('UPDATE users SET is_verified = 1 WHERE id = ?')->execute([$id]);
    flash_set('success', 'Галочка выдана — теперь пользователю доступны любые embed-ссылки для трансляции и расписания без ограничений');
  } elseif ($_POST['action'] === 'unverify') {
    db()->prepare('UPDATE users SET is_verified = 0 WHERE id = ?')->execute([$id]);
    flash_set('success', 'Галочка снята — пользователь снова ограничен стандартными источниками и проверенными embed-ссылками');
  } elseif ($_POST['action'] === 'ban') {
    db()->prepare('UPDATE users SET is_banned = 1 WHERE id = ?')->execute([$id]);
    flash_set('success', 'Пользователь заблокирован — предупреждение теперь видно в профиле, на форуме, в сообщениях и в каналах-рассылках');
  } elseif ($_POST['action'] === 'unban') {
    db()->prepare('UPDATE users SET is_banned = 0 WHERE id = ?')->execute([$id]);
    flash_set('success', 'Блокировка снята');
  }
  redirect('/moderator/users.php');
}

$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
  $stmt = db()->prepare("SELECT id, username, email, role, is_verified, is_banned, created_at FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 100");
  $stmt->execute(['%' . $search . '%', '%' . $search . '%']);
} else {
  $stmt = db()->query('SELECT id, username, email, role, is_verified, is_banned, created_at FROM users ORDER BY id DESC LIMIT 100');
}
$users = $stmt->fetchAll();
?>
<h2>Пользователи</h2>
<p style="color:var(--text-dim);font-size:13px">
  Роль <b>moderator</b> — доступ к этой панели. Роль <b>admin</b> отсюда не выдаётся и не снимается — только через полную админку.<br>
  Галочка ✓ — помимо статуса, даёт владельцу канала право ставить <b>любую</b> embed-ссылку для трансляции и расписания
  без проверки на «похоже на плеер»; без галочки доступны только стандартные источники из /admin/sources.php и ссылки,
  прошедшие обычную проверку (YouTube/VK/Rutube/mp4/m3u8/известные плеерные хосты).
</p>

<form method="GET" style="margin:12px 0">
  <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск по логину или email" style="padding:8px;width:100%;max-width:280px">
  <button class="btn btn-outline btn-sm" type="submit">Найти</button>
</form>

<table class="admin-table" style="width:100%">
  <thead><tr><th>Логин</th><th>Email</th><th>Роль</th><th>Галочка</th><th>Статус</th></tr></thead>
  <tbody>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['username']) ?><?php if ($u['is_verified']): ?> <span class="verify-badge" title="Подтверждённый аккаунт">✓</span><?php endif; ?></td>
      <td><?= e($u['email'] ?: '—') ?></td>
      <td>
        <?php if ($u['role'] === 'admin'): ?>
          <span class="status-pill status-approved">admin</span>
        <?php else: ?>
          <form method="POST" onchange="this.submit()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="role">
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <select name="role">
              <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>user</option>
              <option value="moderator" <?= $u['role'] === 'moderator' ? 'selected' : '' ?>>moderator</option>
            </select>
          </form>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($u['role'] === 'admin'): ?>
          <span style="color:var(--text-dim);font-size:12px">—</span>
        <?php else: ?>
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
        <?php endif; ?>
      </td>
      <td>
        <?php if ($u['role'] === 'admin'): ?>
          <span class="status-pill status-approved">активен</span>
        <?php else: ?>
        <span class="status-pill status-<?= $u['is_banned'] ? 'rejected' : 'approved' ?>"><?= $u['is_banned'] ? 'забанен' : 'активен' ?></span>
        <form method="POST" style="display:inline-block;margin-left:6px">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <?php if ($u['is_banned']): ?>
            <input type="hidden" name="action" value="unban">
            <button class="btn btn-outline btn-sm" type="submit">Разбанить</button>
          <?php else: ?>
            <input type="hidden" name="action" value="ban">
            <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Заблокировать <?= e(addslashes($u['username'])) ?>? Предупреждение будет видно везде на платформе.')">Забанить</button>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
