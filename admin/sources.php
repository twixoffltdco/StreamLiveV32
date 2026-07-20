<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
require_once __DIR__ . '/../includes/embed_helper.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if ($_POST['action'] === 'add') {
    [$isValid, $result] = validate_player_url($_POST['type'], trim($_POST['url']));
    if (!$isValid) {
      flash_set('error', $result);
      redirect('/admin/sources.php');
    }
    db()->prepare('INSERT INTO sources (name, type, url, created_by) VALUES (?, ?, ?, NULL)')
      ->execute([trim($_POST['name']), $_POST['type'], $result]);
  } elseif ($_POST['action'] === 'delete') {
    db()->prepare('DELETE FROM sources WHERE id = ? AND created_by IS NULL')->execute([(int)$_POST['source_id']]);
  }
  redirect('/admin/sources.php');
}
$sources = db()->query('SELECT * FROM sources WHERE created_by IS NULL ORDER BY id DESC')->fetchAll();
?>
<h2>Стандартные источники</h2>
<p style="color:var(--text-dim);font-size:13px">Доступны всем владельцам каналов, если они сами не хотят настраивать свои.</p>
<form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:20px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <div><label>Название</label><input type="text" name="name" required></div>
  <div><label>Тип</label>
    <select name="type">
      <option value="mp4">MP4</option><option value="m3u8">M3U8</option>
      <option value="youtube">YouTube</option><option value="vk">VK</option>
      <option value="rutube">Rutube</option><option value="iframe">Iframe</option>
    </select>
  </div>
  <div style="flex:1"><label>URL</label><input type="url" name="url" required></div>
  <button class="btn btn-primary" type="submit">Добавить</button>
</form>
<table class="admin-table">
  <thead><tr><th>Название</th><th>Тип</th><th>URL</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($sources as $s): ?>
    <tr>
      <td><?= e($s['name']) ?></td><td><?= e($s['type']) ?></td>
      <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis"><?= e($s['url']) ?></td>
      <td>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit">Удалить</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
