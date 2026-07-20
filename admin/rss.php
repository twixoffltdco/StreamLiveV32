<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
require_once __DIR__ . '/../includes/rss.php';

$fetchLog = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $title = trim($_POST['title'] ?? '');
    $feedUrl = trim($_POST['feed_url'] ?? '');
    $categoryId = (int)($_POST['forum_category_id'] ?? 0) ?: null;
    if ($title !== '' && $feedUrl !== '') {
      db()->prepare('INSERT INTO rss_sources (title, feed_url, forum_category_id) VALUES (?, ?, ?)')
        ->execute([$title, $feedUrl, $categoryId]);
      flash_set('success', 'Источник добавлен');
    }
    redirect('/admin/rss.php');
  } elseif ($action === 'delete') {
    db()->prepare('DELETE FROM rss_sources WHERE id = ?')->execute([(int)$_POST['source_id']]);
    flash_set('success', 'Источник удалён');
    redirect('/admin/rss.php');
  } elseif ($action === 'fetch') {
    $fetchLog = fetch_rss_source((int)$_POST['source_id']);
  } elseif ($action === 'fetch_all') {
    $ids = db()->query('SELECT id FROM rss_sources')->fetchAll(PDO::FETCH_COLUMN);
    $total = 0;
    foreach ($ids as $id) {
      $r = fetch_rss_source((int)$id);
      if ($r['ok']) $total += $r['inserted'];
    }
    flash_set('success', "Обновлено источников: " . count($ids) . ", новых записей: {$total}");
    redirect('/admin/rss.php');
  }
}

$sources = db()->query(
  "SELECT rs.*, fc.title AS category_title,
     (SELECT COUNT(*) FROM rss_items ri WHERE ri.source_id = rs.id) AS item_count
   FROM rss_sources rs LEFT JOIN forum_categories fc ON fc.id = rs.forum_category_id ORDER BY rs.id DESC"
)->fetchAll();
$forumCategories = db()->query('SELECT id, title FROM forum_categories ORDER BY sort_order, title')->fetchAll();
?>
<h2>RSS-источники</h2>
<p style="color:var(--text-dim);font-size:13px;margin-bottom:16px">
  Работает с любой стандартной RSS/Atom-лентой. У ВК своего RSS нет — используйте URL RSS-моста
  (например, сервис вида rsshub.app/vk/группа или другой публичный vk→rss мост) в поле «URL ленты».
  Если привязать источник к разделу форума — новые записи публикуются как темы форума автоматически.
  Публичная страница со всеми новостями: <a href="/news.php" style="color:var(--accent-2)">/news.php</a><br>
  Исходящие RSS-ленты (чтобы копировать и вставлять в другие сервисы): форум — <code>/rss_forum.php</code>,
  ТВ-каналы — <code>/rss_channels.php?type=tv</code>, радио — <code>/rss_channels.php?type=radio</code>.
</p>

<?php if ($fetchLog): ?>
  <div class="alert <?= $fetchLog['ok'] ? 'alert-success' : 'alert-error' ?>">
    <?= $fetchLog['ok'] ? "Найдено: {$fetchLog['found']}, новых: {$fetchLog['inserted']}" : e($fetchLog['error']) ?>
  </div>
<?php endif; ?>

<div class="form-card form-wide" style="margin-bottom:24px">
  <h3>Новый источник</h3>
  <form method="POST" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div><label>Название</label><input type="text" name="title" required></div>
    <div style="flex:1;min-width:260px"><label>URL ленты (RSS/Atom, для ВК — мост)</label><input type="url" name="feed_url" required></div>
    <div>
      <label>Публиковать в раздел форума (не обязательно)</label>
      <select name="forum_category_id">
        <option value="">— не публиковать в форум —</option>
        <?php foreach ($forumCategories as $fc): ?><option value="<?= (int)$fc['id'] ?>"><?= e($fc['title']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit">Добавить</button>
  </form>
</div>

<form method="POST" style="margin-bottom:16px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="fetch_all">
  <button class="btn btn-outline" type="submit">Обновить все источники сейчас</button>
</form>

<table class="admin-table">
  <thead><tr><th>Название</th><th>Лента</th><th>Раздел форума</th><th>Записей</th><th>Обновлено</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($sources as $s): ?>
    <tr>
      <td><?= e($s['title']) ?></td>
      <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($s['feed_url']) ?></td>
      <td><?= e($s['category_title'] ?: '—') ?></td>
      <td><?= (int)$s['item_count'] ?></td>
      <td><?= $s['last_fetched_at'] ? e($s['last_fetched_at']) : 'никогда' ?></td>
      <td style="display:flex;gap:6px">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="fetch">
          <input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>">
          <button class="btn btn-outline btn-sm" type="submit">Обновить</button>
        </form>
        <form method="POST" onsubmit="return confirm('Удалить источник вместе со всеми загруженными записями?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>">
          <button class="btn btn-danger btn-sm" type="submit">Удалить</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($sources)): ?><tr><td colspan="6" style="color:var(--text-dim)">Источников пока нет</td></tr><?php endif; ?>
  </tbody>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
