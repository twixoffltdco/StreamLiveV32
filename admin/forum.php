<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $title = trim($_POST['title'] ?? '');
    if ($title !== '') {
      db()->prepare('INSERT INTO forum_categories (title, description, sort_order) VALUES (?, ?, ?)')
        ->execute([$title, trim($_POST['description'] ?? '') ?: null, (int)($_POST['sort_order'] ?? 0)]);
      flash_set('success', 'Категория создана');
    }
  } elseif ($action === 'update') {
    $id = (int)$_POST['category_id'];
    db()->prepare('UPDATE forum_categories SET title = ?, description = ?, sort_order = ? WHERE id = ?')
      ->execute([trim($_POST['title']), trim($_POST['description'] ?? '') ?: null, (int)($_POST['sort_order'] ?? 0), $id]);
    flash_set('success', 'Категория обновлена');
  } elseif ($action === 'delete') {
    db()->prepare('DELETE FROM forum_categories WHERE id = ?')->execute([(int)$_POST['category_id']]);
    flash_set('success', 'Категория удалена (вместе со всеми темами и сообщениями)');
  } elseif ($action === 'add_forum_mod') {
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([trim($_POST['username'] ?? '')]);
    $u = $stmt->fetch();
    if ($u) {
      db()->prepare('INSERT IGNORE INTO forum_moderators (user_id) VALUES (?)')->execute([$u['id']]);
      flash_set('success', 'Модератор форума добавлен');
    } else {
      flash_set('error', 'Пользователь с таким логином не найден');
    }
  } elseif ($action === 'remove_forum_mod') {
    db()->prepare('DELETE FROM forum_moderators WHERE user_id = ?')->execute([(int)$_POST['user_id']]);
    flash_set('success', 'Модератор форума снят');
  }
  redirect('/admin/forum.php');
}

$categories = db()->query(
  "SELECT fc.*, (SELECT COUNT(*) FROM forum_threads t WHERE t.category_id = fc.id AND t.is_deleted = 0) AS thread_count
   FROM forum_categories fc ORDER BY fc.sort_order ASC, fc.id ASC"
)->fetchAll();

$forumMods = db()->query(
  "SELECT u.id, u.username FROM forum_moderators fm JOIN users u ON u.id = fm.user_id ORDER BY u.username"
)->fetchAll();
?>
<h2>Категории форума</h2>
<p style="color:var(--text-dim);font-size:13px;margin-bottom:16px">Публичная страница форума: <a href="/forum.php" style="color:var(--accent-2)">/forum.php</a></p>

<div class="form-card form-wide" style="margin-bottom:24px">
  <h3>Новая категория</h3>
  <form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div><label>Название</label><input type="text" name="title" required></div>
    <div style="flex:1"><label>Описание</label><input type="text" name="description"></div>
    <div><label>Порядок</label><input type="number" name="sort_order" value="0" style="width:80px"></div>
    <button class="btn btn-primary" type="submit">Создать</button>
  </form>
</div>

<table class="admin-table">
  <thead><tr><th>Название</th><th>Описание</th><th>Порядок</th><th>Тем</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($categories as $c): $fid = 'cat-edit-' . (int)$c['id']; $did = 'cat-del-' . (int)$c['id']; ?>
    <tr>
      <td><input form="<?= $fid ?>" type="text" name="title" value="<?= e($c['title']) ?>" style="width:160px"></td>
      <td><input form="<?= $fid ?>" type="text" name="description" value="<?= e($c['description']) ?>" style="width:220px"></td>
      <td><input form="<?= $fid ?>" type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>" style="width:70px"></td>
      <td><?= (int)$c['thread_count'] ?></td>
      <td style="display:flex;gap:6px">
        <form id="<?= $fid ?>" method="POST" style="display:contents">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit">Сохранить</button>
        </form>
        <form id="<?= $did ?>" method="POST" style="display:contents" onsubmit="return confirm('Удалить категорию вместе со всеми темами и сообщениями?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-danger btn-sm" type="submit">Удалить</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($categories)): ?><tr><td colspan="5" style="color:var(--text-dim)">Категорий пока нет</td></tr><?php endif; ?>
  </tbody>
</table>

<div class="form-card form-wide" style="margin-top:24px">
  <h3>Модераторы форума</h3>
  <p style="color:var(--text-dim);font-size:13px">Могут закреплять/закрывать темы и удалять сообщения на форуме (без доступа к остальной админке).</p>
  <form method="POST" style="display:flex;gap:10px;align-items:end;margin-top:10px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_forum_mod">
    <div style="flex:1"><label>Логин пользователя</label><input type="text" name="username" required></div>
    <button class="btn btn-primary" type="submit">Назначить</button>
  </form>
  <ul style="margin-top:10px">
    <?php foreach ($forumMods as $m): ?>
      <li style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
        <span><?= e($m['username']) ?></span>
        <form method="POST" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_forum_mod">
          <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
          <button type="submit" style="background:none;border:none;color:var(--danger);font-size:12px;cursor:pointer">снять</button>
        </form>
      </li>
    <?php endforeach; ?>
    <?php if (empty($forumMods)): ?><li style="color:var(--text-dim);font-size:13px;list-style:none">Пока никого не назначили</li><?php endif; ?>
  </ul>
</div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
