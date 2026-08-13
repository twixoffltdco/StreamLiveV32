<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notify.php';

$user = current_user();
if (!$user) {
  redirect('/auth/login');
}
notify_ensure_schema();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'mark_all') {
    try {
      db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
      flash_set('success', 'Все прочитаны');
    } catch (Throwable $e) {}
  } elseif ($action === 'mark_one') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      try {
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
      } catch (Throwable $e) {}
    }
  }
  redirect('/notifications');
}

$rows = [];
try {
  $st = db()->prepare(
    'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100'
  );
  $st->execute([$uid]);
  $rows = $st->fetchAll() ?: [];
} catch (Throwable $e) {}

$unread = 0;
foreach ($rows as $r) {
  if (empty($r['is_read'])) $unread++;
}

$pageTitle = 'Уведомления';
require_once __DIR__ . '/includes/header.php';

$typeLabel = static function (string $t): string {
  $map = [
    'message' => 'Сообщение',
    'video_new' => 'Видео',
    'forum_reply' => 'Форум',
    'schedule_soon' => 'Скоро эфир',
    'schedule_start' => 'Эфир',
    'system' => 'Система',
  ];
  return $map[$t] ?? $t;
};
?>
<div class="container" style="max-width:720px;padding:20px 16px 48px">
  <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px">
    <h1 style="margin:0;font-size:1.4rem">Уведомления <?php if ($unread): ?><span style="font-size:13px;color:var(--accent-2)">(<?= (int)$unread ?> новых)</span><?php endif; ?></h1>
    <?php if ($rows): ?>
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all">
      <button type="submit" class="btn btn-outline btn-sm">Отметить все прочитанными</button>
    </form>
    <?php endif; ?>
  </div>

  <p style="color:var(--text-dim);font-size:13px;margin-bottom:16px">
    Сообщения, эфиры по ★ избранным каналам, форум, новые видео. Браузерный push — если разрешил уведомления.
  </p>

  <?php if (!$rows): ?>
    <p style="color:var(--text-dim)">Пока пусто. Добавь каналы в избранное и разреши уведомления в браузере.</p>
  <?php endif; ?>

  <div class="notif-list">
    <?php foreach ($rows as $r):
      $link = trim((string)($r['link'] ?? ''));
      $isUnread = empty($r['is_read']);
      ?>
      <div class="notif-item <?= $isUnread ? 'unread' : '' ?>">
        <div class="notif-type"><?= e($typeLabel((string)$r['type'])) ?></div>
        <div class="notif-msg"><?= e($r['message']) ?></div>
        <div class="notif-meta">
          <span><?= e($r['created_at'] ?? '') ?></span>
          <?php if ($link !== ''): ?>
            <a href="<?= e($link) ?>" style="color:var(--accent-2)">Открыть</a>
          <?php endif; ?>
          <?php if ($isUnread): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_one">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm" style="padding:2px 8px;font-size:11px">Прочитано</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<style>
.notif-item{padding:12px 14px;border-radius:12px;background:var(--card,#16161e);border:1px solid rgba(255,255,255,.06);margin-bottom:10px}
.notif-item.unread{border-color:rgba(62,166,255,.45);background:rgba(62,166,255,.08)}
.notif-type{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-dim,#999);margin-bottom:4px}
.notif-msg{font-size:14px;line-height:1.4}
.notif-meta{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:8px;font-size:12px;color:var(--text-dim,#999)}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
