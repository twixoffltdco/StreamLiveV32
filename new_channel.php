<?php
$pageTitle = 'Создать канал';
require_once __DIR__ . '/includes/header.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $title = trim($_POST['title'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $type = $_POST['type'] === 'radio' ? 'radio' : 'tv';
  $logo = trim($_POST['logo_url'] ?? '') ?: null;
  $sourceId = $_POST['default_source_id'] !== '' ? (int)$_POST['default_source_id'] : null;

  if ($title === '') {
    flash_set('error', 'Укажите название канала');
    redirect('/new_channel.php');
  }

  $slug = slugify($title);
  $stmt = db()->prepare(
    'INSERT INTO channels (owner_id, slug, title, description, type, logo_url, default_source_id, seo_title, seo_description)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->execute([$__user['id'], $slug, $title, $description, $type, $logo, $sourceId, $title, mb_substr($description, 0, 380)]);
  $newChannelId = (int)db()->lastInsertId();
  maybe_auto_approve_channel($newChannelId);
  $stmt2 = db()->prepare('SELECT status FROM channels WHERE id = ?');
  $stmt2->execute([$newChannelId]);
  $finalStatus = $stmt2->fetchColumn();
  flash_set('success', $finalStatus === 'approved'
    ? 'Канал создан и сразу одобрен — оформление полное, всё на месте!'
    : 'Канал отправлен на модерацию. Чтобы пройти её автоматически: заполните название, описание (от 20 символов), логотип и рабочую embed-ссылку на источник вещания.');
  redirect('/dashboard.php');
}

$sources = db()->query('SELECT * FROM sources WHERE created_by IS NULL')->fetchAll();
?>
<div class="container">
  <div class="form-card form-wide">
    <h2>Создать канал или радио</h2>
    <p style="color:var(--text-dim);font-size:13px">После создания канал попадёт на модерацию. Как только администратор одобрит — он появится в каталоге.</p>
    <form method="POST" action="/new_channel">
      <?= csrf_field() ?>
      <label>Тип</label>
      <select name="type">
        <option value="tv">Телеканал</option>
        <option value="radio">Радио</option>
      </select>
      <label>Название</label>
      <input type="text" name="title" required>
      <label>Описание</label>
      <textarea name="description"></textarea>
      <label>Логотип (прямая ссылка на изображение)</label>
      <input type="url" name="logo_url" placeholder="https://...">
      <label>Источник вещания по умолчанию</label>
      <select name="default_source_id">
        <option value="">— выбрать позже в настройках —</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?> (<?= e($s['type']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary" style="margin-top:20px;width:100%" type="submit">Отправить на модерацию</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
