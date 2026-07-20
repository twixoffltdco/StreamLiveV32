<?php
require_once __DIR__ . '/../includes/resources.php';
require_once __DIR__ . '/../includes/moderator_auth.php';
$u = require_moderator();
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); resources_ensure_table(); $id=(int)$_POST['id']; $action=$_POST['action'] ?? ''; if ($action === 'hide') db()->prepare("UPDATE resources SET status='hidden', hidden_reason=? WHERE id=?")->execute([trim($_POST['reason'] ?? 'Скрыто модератором'),$id]); if ($action === 'restore') db()->prepare("UPDATE resources SET status='published', hidden_reason=NULL WHERE id=?")->execute([$id]); redirect('/moderator/resources.php'); }
$items = resources_list(true, 200);
require_once __DIR__ . '/_layout_start.php';
?>
<h1>Модерация ресурсов</h1><table class="admin-table" style="width:100%"><tr><th>ID</th><th>Ресурс</th><th>Автор</th><th>Статус</th><th>Действие</th></tr><?php foreach($items as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><a href="<?= e(resource_url($r)) ?>"><?= e($r['title']) ?></a><br><small><?= e($r['external_url']) ?></small></td><td><?= e($r['username']) ?></td><td><?= e($r['status']) ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?php if($r['status']==='published'): ?><input name="reason" placeholder="Причина" value="Нарушение правил"><button class="btn btn-outline" name="action" value="hide">Скрыть</button><?php else: ?><button class="btn btn-primary" name="action" value="restore">Вернуть</button><?php endif; ?></form></td></tr><?php endforeach; ?></table>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
