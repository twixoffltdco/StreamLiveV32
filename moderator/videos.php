<?php require_once __DIR__ . '/_layout_start.php'; ?>
<?php
require_once __DIR__ . '/../includes/moderation_limits.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $id = (int)$_POST['video_id'];
  $stmt = db()->prepare('SELECT * FROM videos WHERE id = ?');
  $stmt->execute([$id]);
  $video = $stmt->fetch();

  if ($video) {
    // Запрет самомодерации: нельзя одобрить/отклонить своё же видео.
    if (is_self_moderation_blocked((int)$video['user_id'], (int)$__user['id'])) {
      flash_set('error', 'Нельзя модерировать своё же видео — это должен сделать другой модератор.');
      redirect('/moderator/videos.php');
    }
    // Кулдаун: 1 действие модерации видео в N часов на модератора (не касается админа).
    $__cooldownLeft = moderator_cooldown_remaining_hours((int)$__user['id'], 'video', $__user['role']);
    if ($__cooldownLeft !== null && in_array($_POST['action'] ?? '', ['reject', 'restore'], true)) {
      flash_set('error', "Лимит модерации видео — 1 действие в " . moderation_cooldown_hours() . " ч. Следующее доступно через {$__cooldownLeft} ч.");
      redirect('/moderator/videos.php');
    }
    $isAdmin = $__user['role'] === 'admin';
    if ($_POST['action'] === 'reject') {
      $reason = trim($_POST['reason'] ?? '') ?: 'Нарушение правил платформы';
      db()->prepare("UPDATE videos SET status='rejected', reject_reason=?, moderated_by=?, moderated_at=NOW(), locked_by_admin=? WHERE id=?")
        ->execute([$reason, $__user['id'], $isAdmin ? 1 : 0, $id]);
      try {
        db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message) VALUES (?, ?, "video_rejected", ?)')
          ->execute([$video['user_id'], $video['channel_id'], "Видео «{$video['title']}» отклонено модератором: {$reason}"]);
      } catch (\Throwable $e) { /* таблица notifications может отличаться — модерация всё равно применилась */ }
      moderation_log_action((int)$__user['id'], 'video', $id, 'reject');
      flash_set('success', 'Видео отклонено и скрыто с сайта');
    } elseif ($_POST['action'] === 'restore') {
      if (!empty($video['locked_by_admin']) && !$isAdmin) {
        flash_set('error', 'Это видео ранее отклонено администратором — только администратор может изменить решение.');
        redirect('/moderator/videos.php');
      }
      db()->prepare("UPDATE videos SET status='published', reject_reason=NULL, moderated_by=?, moderated_at=NOW(), locked_by_admin=0 WHERE id=?")
        ->execute([$__user['id'], $id]);
      try {
        db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message) VALUES (?, ?, "video_restored", ?)')
          ->execute([$video['user_id'], $video['channel_id'], "Видео «{$video['title']}» восстановлено и снова доступно на сайте"]);
      } catch (\Throwable $e) { /* см. выше */ }
      moderation_log_action((int)$__user['id'], 'video', $id, 'restore');
      flash_set('success', 'Видео восстановлено');
    }
  }
  redirect('/moderator/videos.php');
}

$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
  $stmt = db()->prepare(
    "SELECT v.*, u.username AS owner_name, c.title AS channel_title FROM videos v
     JOIN users u ON u.id = v.user_id JOIN channels c ON c.id = v.channel_id
     WHERE v.status IN ('published','rejected') AND v.title LIKE ?
     ORDER BY v.status='rejected' DESC, v.created_at DESC LIMIT 100"
  );
  $stmt->execute(['%' . $search . '%']);
} else {
  $stmt = db()->query(
    "SELECT v.*, u.username AS owner_name, c.title AS channel_title FROM videos v
     JOIN users u ON u.id = v.user_id JOIN channels c ON c.id = v.channel_id
     WHERE v.status IN ('published','rejected')
     ORDER BY v.status='rejected' DESC, v.created_at DESC LIMIT 100"
  );
}
$videos = $stmt->fetchAll();
?>
<h2>Модерация видео</h2>
<p style="color:var(--text-dim);font-size:13px">Видео публикуются сразу при импорте — здесь можно отклонить (скрыть с сайта с указанием причины автору) уже опубликованное видео, если оно нарушает правила, либо вернуть его обратно в каталог.</p>

<form method="GET" style="margin:12px 0">
  <input type="text" name="q" value="<?= e($search) ?>" placeholder="Поиск по названию" style="padding:8px;width:100%;max-width:280px">
  <button class="btn btn-outline btn-sm" type="submit">Найти</button>
</form>

<?php if (empty($videos)): ?>
  <div class="empty-state">Видео не найдено</div>
<?php endif; ?>

<div style="overflow-x:auto"><table class="admin-table" style="width:100%;min-width:640px">
  <thead><tr><th>Видео</th><th>Канал / автор</th><th>Статус</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($videos as $v): ?>
    <tr>
      <td style="display:flex;gap:10px;align-items:center;min-width:220px">
        <div style="width:64px;aspect-ratio:16/9;flex-shrink:0;background:#111 url('<?= e($v['thumbnail_url'] ?: '/assets/img/video-placeholder.png') ?>') center/cover;border-radius:6px"></div>
        <a href="/video.php?slug=<?= e($v['slug']) ?>" style="color:var(--accent-2)" target="_blank"><?= e(mb_substr($v['title'], 0, 60)) ?></a>
      </td>
      <td><?= e($v['channel_title']) ?> <span style="color:var(--text-dim)">· <?= e($v['owner_name']) ?></span></td>
      <td>
        <span class="status-pill status-<?= $v['status'] === 'published' ? 'approved' : 'rejected' ?>"><?= e($v['status']) ?></span>
        <?php if ($v['status'] === 'rejected' && $v['reject_reason']): ?><div style="font-size:12px;color:var(--text-dim)"><?= e($v['reject_reason']) ?></div><?php endif; ?>
      </td>
      <td style="display:flex;gap:6px;flex-wrap:wrap">
        <?php if ($v['status'] === 'published'): ?>
        <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="video_id" value="<?= (int)$v['id'] ?>">
          <input type="hidden" name="action" value="reject">
          <input type="text" name="reason" placeholder="Причина (спам/18+/копирайт...)" style="width:100%;max-width:220px;min-width:140px">
          <button class="btn btn-danger btn-sm" type="submit">Отклонить</button>
        </form>
        <?php else: ?>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="video_id" value="<?= (int)$v['id'] ?>">
          <input type="hidden" name="action" value="restore">
          <button class="btn btn-ok btn-sm" type="submit">Восстановить</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
