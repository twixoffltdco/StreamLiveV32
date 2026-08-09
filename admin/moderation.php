<?php
require_once __DIR__ . '/_layout_start.php';

$err = '';
$channels = [];

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_verify')) csrf_verify();
    $id = (int)($_POST['channel_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $channel = null;
    try {
      $stmt = db()->prepare('SELECT * FROM channels WHERE id = ?');
      $stmt->execute([$id]);
      $channel = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
      $err = 'БД: ' . $e->getMessage();
    }

    if ($channel) {
      try {
        if ($action === 'approve') {
          db()->prepare("UPDATE channels SET status='approved', reject_reason=NULL WHERE id=?")->execute([$id]);
          try {
            db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message) VALUES (?, ?, "channel_approved", ?)')
              ->execute([(int)$channel['owner_id'], $id, "Канал «{$channel['title']}» прошёл модерацию и опубликован"]);
          } catch (Throwable $e) {}
          if (function_exists('flash_set')) flash_set('success', 'Канал одобрен');
        } elseif ($action === 'reject') {
          $reason = trim((string)($_POST['reason'] ?? '')) ?: 'без указания причины';
          db()->prepare("UPDATE channels SET status='rejected', reject_reason=? WHERE id=?")->execute([$reason, $id]);
          try {
            db()->prepare('INSERT INTO notifications (user_id, channel_id, type, message) VALUES (?, ?, "channel_rejected", ?)')
              ->execute([(int)$channel['owner_id'], $id, "Канал «{$channel['title']}» отклонён: {$reason}"]);
          } catch (Throwable $e) {}
          if (function_exists('flash_set')) flash_set('success', 'Канал отклонён');
        }
      } catch (Throwable $e) {
        $err = 'Действие: ' . $e->getMessage();
      }
      if (function_exists('redirect') && $err === '') {
        redirect('/admin/moderation.php');
      }
    }
  }

  try {
    $channels = db()->query(
      "SELECT c.*, u.username AS owner_name
       FROM channels c
       LEFT JOIN users u ON u.id = c.owner_id
       WHERE c.status = 'pending'
       ORDER BY c.created_at ASC"
    )->fetchAll() ?: [];
  } catch (Throwable $e) {
    $err = ($err ? $err . ' | ' : '') . 'Список: ' . $e->getMessage();
    $channels = [];
  }
} catch (Throwable $e) {
  $err = 'Критическая: ' . $e->getMessage();
}
?>
<h2>Модерация каналов</h2>
<p style="opacity:.75;margin-bottom:12px">
  <a href="/admin/forum_moderation.php">→ Модерация форума (темы / сообщения)</a>
</p>
<?php if ($err): ?>
  <div style="padding:12px;margin:12px 0;border-radius:10px;background:rgba(251,113,133,.15);color:#fecdd3">
    Ошибка (страница не упала): <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>
<?php if (empty($channels)): ?>
  <div class="empty-state">Очередь каналов пуста</div>
<?php endif; ?>
<?php foreach ($channels as $c): ?>
  <div class="card mini-preview" style="padding:18px;margin-bottom:14px">
    <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
      <?php if (!empty($c['logo_url'])): ?>
        <img src="<?= e($c['logo_url']) ?>" style="width:72px;height:72px;border-radius:14px;object-fit:cover;border:1px solid rgba(255,255,255,.1)" alt="">
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:14px;background:#222;display:flex;align-items:center;justify-content:center;opacity:.5">нет лого</div>
      <?php endif; ?>
      <div style="flex:1;min-width:200px">
        <b style="font-size:16px"><?= e($c['title'] ?? '') ?></b>
        <div style="color:var(--text-dim);font-size:13px;margin-top:4px">
          <?= (($c['type'] ?? '') === 'radio') ? 'Радио' : 'ТВ' ?>
          · автор: <?= e($c['owner_name'] ?? '—') ?>
          · slug: <code><?= e($c['slug'] ?? '') ?></code>
        </div>
        <p style="color:var(--text-dim);font-size:13px;margin:8px 0"><?= e($c['description'] ?: 'Без описания') ?></p>
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;align-items:center">
      <form method="POST">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="action" value="approve">
        <button class="btn btn-ok btn-sm" type="submit">Одобрить</button>
      </form>
      <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <input type="hidden" name="channel_id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="action" value="reject">
        <input type="text" name="reason" placeholder="Причина отказа" style="width:180px">
        <button class="btn btn-danger btn-sm" type="submit">Отклонить</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
