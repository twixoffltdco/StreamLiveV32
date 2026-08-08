<?php
/**
 * Импорт JSON-бэкапа канала (format channel_backup_v1).
 * Восстанавливает метаданные канала, источники, расписание, видео (без файлов).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$u = current_user();
if (!$u) { flash_set('error', 'Войдите'); redirect('/auth/login.php'); }

$channelId = (int)($_POST['channel_id'] ?? $_GET['id'] ?? 0);
if ($channelId <= 0) { flash_set('error', 'Не указан канал'); redirect('/'); }

$st = db()->prepare('SELECT * FROM channels WHERE id = ?');
$st->execute([$channelId]);
$ch = $st->fetch();
if (!$ch) { flash_set('error', 'Канал не найден'); redirect('/'); }

$isAdmin = in_array(($u['role'] ?? ''), ['admin', 'moderator'], true) || !empty($u['is_admin']);
if ((int)($ch['user_id'] ?? $ch['owner_id'] ?? 0) !== (int)$u['id'] && !$isAdmin) {
  flash_set('error', 'Нет доступа'); redirect('/channel_manage.php?id=' . $channelId);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  flash_set('error', 'Нужен POST с файлом'); redirect('/channel_manage.php?id=' . $channelId);
}
if (function_exists('csrf_verify')) csrf_verify();

$mode = $_POST['mode'] ?? 'merge'; // merge | replace_schedule
$raw = '';
if (!empty($_POST['from_preview'])) {
  $tmp = sys_get_temp_dir() . '/sl_imp_' . (int)$u['id'] . '_' . $channelId . '.json';
  if (is_file($tmp)) $raw = (string)file_get_contents($tmp);
}

if (!empty($_FILES['backup']['tmp_name']) && is_uploaded_file($_FILES['backup']['tmp_name'])) {
  $raw = (string)file_get_contents($_FILES['backup']['tmp_name']);
} elseif (!empty($_POST['backup_json'])) {
  $raw = (string)$_POST['backup_json'];
}
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['channel'])) {
  flash_set('error', 'Неверный JSON бэкапа'); redirect('/channel_manage.php?id=' . $channelId);
}

$pdo = db();
$pdo->beginTransaction();
$stats = ['sources' => 0, 'schedule' => 0, 'videos' => 0, 'channel' => 0];

try {
  // 1) Канал — безопасные поля
  $srcCh = $data['channel'];
  $fields = [];
  $vals = [];
  foreach (['title', 'description', 'logo_url', 'seo_title', 'seo_description', 'seo_keywords'] as $f) {
    if (array_key_exists($f, $srcCh) && $srcCh[$f] !== null) {
      $fields[] = "$f = ?";
      $vals[] = $srcCh[$f];
    }
  }
  if (array_key_exists('is_public', $srcCh)) {
    $fields[] = 'is_public = ?';
    $vals[] = (int)$srcCh['is_public'];
  }
  if (array_key_exists('paid_content', $srcCh)) {
    $fields[] = 'paid_content = ?';
    $vals[] = (int)$srcCh['paid_content'];
  }
  if ($fields) {
    $vals[] = $channelId;
    $pdo->prepare('UPDATE channels SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    $stats['channel'] = 1;
  }

  // 2) Источники: map old_id -> new_id
  $sourceMap = [];
  foreach ($data['sources'] ?? [] as $src) {
    $name = trim((string)($src['name'] ?? 'source'));
    $type = trim((string)($src['type'] ?? 'url'));
    $url = trim((string)($src['url'] ?? ''));
    if ($url === '') continue;
    // ищем такой же url у пользователя
    $find = $pdo->prepare('SELECT id FROM sources WHERE url = ? AND (created_by = ? OR created_by IS NULL) LIMIT 1');
    $find->execute([$url, (int)$u['id']]);
    $existId = (int)$find->fetchColumn();
    if ($existId > 0) {
      $sourceMap[(int)($src['id'] ?? 0)] = $existId;
      continue;
    }
    $pdo->prepare('INSERT INTO sources (name, type, url, created_by) VALUES (?,?,?,?)')
      ->execute([$name, $type, $url, (int)$u['id']]);
    $newId = (int)$pdo->lastInsertId();
    $sourceMap[(int)($src['id'] ?? 0)] = $newId;
    $stats['sources']++;
  }

  // default_source_id
  if (!empty($srcCh['default_source_id']) && isset($sourceMap[(int)$srcCh['default_source_id']])) {
    $pdo->prepare('UPDATE channels SET default_source_id = ? WHERE id = ?')
      ->execute([$sourceMap[(int)$srcCh['default_source_id']], $channelId]);
  }

  // 3) Расписание
  if ($mode === 'replace_schedule') {
    $pdo->prepare('DELETE FROM schedule WHERE channel_id = ?')->execute([$channelId]);
  }
  foreach ($data['schedule'] ?? [] as $slot) {
    $oldSid = (int)($slot['source_id'] ?? 0);
    $newSid = $sourceMap[$oldSid] ?? null;
    if ($newSid === null && $oldSid > 0) {
      // оставим null / 0
      $newSid = 0;
    }
    $pdo->prepare('INSERT INTO schedule (channel_id, day_of_week, start_time, end_time, source_id, program_title) VALUES (?,?,?,?,?,?)')
      ->execute([
        $channelId,
        (int)($slot['day_of_week'] ?? 0),
        $slot['start_time'] ?? '00:00:00',
        $slot['end_time'] ?? '23:59:59',
        $newSid ?: null,
        $slot['program_title'] ?? null,
      ]);
    $stats['schedule']++;
  }

  // 4) Видео — по slug, не дублируем
  foreach ($data['videos'] ?? [] as $v) {
    $slug = trim((string)($v['slug'] ?? ''));
    $title = trim((string)($v['title'] ?? 'Video'));
    if ($slug === '') {
      $slug = 'import-' . substr(md5($title . microtime(true)), 0, 10);
    }
    $check = $pdo->prepare('SELECT id FROM videos WHERE slug = ? LIMIT 1');
    $check->execute([$slug]);
    if ($check->fetch()) continue;

    $cols = ['channel_id', 'user_id', 'slug', 'title', 'description', 'tags', 'source_url', 'platform', 'embed_url', 'thumbnail_url', 'status'];
    $vals = [
      $channelId,
      (int)$u['id'],
      $slug,
      $title,
      $v['description'] ?? null,
      $v['tags'] ?? null,
      $v['source_url'] ?? null,
      $v['platform'] ?? null,
      $v['embed_url'] ?? null,
      $v['thumbnail_url'] ?? null,
      $v['status'] ?? 'published',
    ];
    // optional premiere
    $extra = '';
    try {
      $pdo->query('SELECT premiere_at FROM videos LIMIT 1');
      $cols[] = 'premiere_at';
      $cols[] = 'is_premiere';
      $vals[] = $v['premiere_at'] ?? null;
      $vals[] = (int)($v['is_premiere'] ?? 0);
    } catch (Throwable $e) {}

    $ph = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare('INSERT INTO videos (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
    $stats['videos']++;
  }

  $pdo->commit();
  flash_set('success', sprintf(
    'Импорт готов: канал %d, источников +%d, слотов расписания +%d, видео +%d',
    $stats['channel'], $stats['sources'], $stats['schedule'], $stats['videos']
  ));
} catch (Throwable $e) {
  $pdo->rollBack();
  flash_set('error', 'Ошибка импорта: ' . $e->getMessage());
}

redirect('/channel_manage.php?id=' . $channelId);
