<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mod_bots.php';
require_admin();
mod_bots_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process') {
  csrf_verify();
  $st = mod_bots_process_queue(30);
  flash_set('success', "Очередь мод-ботов: OK {$st['done']}, err {$st['error']}");
  redirect('/admin/mod_bots.php');
}

$rows = [];
try {
  $rows = db()->query(
    "SELECT m.user_id, m.tg_enabled, m.bot_username, m.auto_resource, m.auto_video, m.auto_forum, m.neuroham_enabled, m.updated_at,
            u.username,
            (m.tg_token_enc IS NOT NULL AND m.tg_token_enc != '') AS has_token
     FROM mod_bots m
     LEFT JOIN users u ON u.id = m.user_id
     ORDER BY m.updated_at DESC"
  )->fetchAll() ?: [];
} catch (Throwable $e) {}

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Боты модераторов</h2>
<p style="color:var(--text-dim);font-size:13px">Токены <b>недоступны</b> даже админу — только статус. Настройка у модератора: <code>/moderator/my_bot.php</code></p>
<form method="POST" style="margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="action" value="process"><button class="btn btn-outline btn-sm">Прогнать очередь постов</button></form>
<table class="table" style="width:100%;font-size:13px">
  <tr><th>Модератор</th><th>Бот</th><th>Вкл</th><th>Токен</th><th>Ресурсы</th><th>Видео</th><th>Форум</th><th>Нейрохам</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td>@<?= e($r['username'] ?? $r['user_id']) ?></td>
      <td><?= $r['bot_username'] ? '@' . e($r['bot_username']) : '—' ?></td>
      <td><?= !empty($r['tg_enabled']) ? '✓' : '—' ?></td>
      <td><?= !empty($r['has_token']) ? 'зашифрован' : 'нет' ?></td>
      <td><?= !empty($r['auto_resource']) ? '✓' : '' ?></td>
      <td><?= !empty($r['auto_video']) ? '✓' : '' ?></td>
      <td><?= !empty($r['auto_forum']) ? '✓' : '' ?></td>
      <td><?= !empty($r['neuroham_enabled']) ? '✓' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" style="color:var(--text-dim)">Пока никто не подключил</td></tr><?php endif; ?>
</table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
