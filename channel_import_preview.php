<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$u = current_user();
if (!$u) { redirect('/auth/login.php'); }

$channelId = (int)($_POST['channel_id'] ?? 0);
if ($channelId <= 0 || empty($_FILES['backup']['tmp_name'])) {
  flash_set('error', 'Выберите файл бэкапа'); redirect('/channel_manage.php?id=' . $channelId);
}
$raw = file_get_contents($_FILES['backup']['tmp_name']);
$data = json_decode((string)$raw, true);
if (!is_array($data) || empty($data['channel'])) {
  flash_set('error', 'Неверный JSON'); redirect('/channel_manage.php?id=' . $channelId);
}

// store temp for confirm
$tmp = sys_get_temp_dir() . '/sl_imp_' . (int)$u['id'] . '_' . $channelId . '.json';
file_put_contents($tmp, $raw);

$ch = $data['channel'];
$ns = count($data['sources'] ?? []);
$nsch = count($data['schedule'] ?? []);
$nv = count($data['videos'] ?? []);

$pageTitle = 'Превью импорта';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:720px;margin:24px auto">
  <h1>Превью импорта канала</h1>
  <p style="opacity:.75">Ничего ещё не записано. Проверьте и подтвердите.</p>
  <div class="form-card" style="padding:16px;margin:16px 0">
    <p><b>Из бэкапа:</b> <?= e($ch['title'] ?? '—') ?> · slug <?= e($ch['slug'] ?? '—') ?></p>
    <ul>
      <li>Источников: <b><?= (int)$ns ?></b></li>
      <li>Слотов расписания: <b><?= (int)$nsch ?></b></li>
      <li>Видео: <b><?= (int)$nv ?></b></li>
    </ul>
    <?php if (!empty($data['schedule'])): ?>
      <details><summary>Слоты</summary>
        <ul style="font-size:13px"><?php foreach (array_slice($data['schedule'],0,20) as $s): ?>
          <li><?= (int)($s['day_of_week']??0) ?> · <?= e(substr((string)($s['start_time']??''),0,5)) ?>–<?= e(substr((string)($s['end_time']??''),0,5)) ?> · <?= e($s['program_title']??'') ?></li>
        <?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
    <?php if (!empty($data['videos'])): ?>
      <details><summary>Видео (первые 15)</summary>
        <ul style="font-size:13px"><?php foreach (array_slice($data['videos'],0,15) as $v): ?>
          <li><?= e($v['title']??'') ?> <code><?= e($v['slug']??'') ?></code></li>
        <?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
  </div>
  <form method="POST" action="/channel_import.php">
    <?php if (function_exists('csrf_field')) echo csrf_field(); ?>
    <input type="hidden" name="channel_id" value="<?= (int)$channelId ?>">
    <input type="hidden" name="from_preview" value="1">
    <label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="radio" name="mode" value="merge" checked> Merge</label>
    <label style="display:flex;gap:8px;align-items:center;margin:8px 0"><input type="radio" name="mode" value="replace_schedule"> Заменить расписание</label>
    <button class="btn btn-primary" type="submit">Подтвердить импорт</button>
    <a class="btn btn-outline" href="/channel_manage.php?id=<?= (int)$channelId ?>">Отмена</a>
  </form>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
