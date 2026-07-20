<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stats.php';
$__user = current_user();
$isStaff = $__user && in_array($__user['role'], ['moderator', 'admin'], true);

$snapshot = online_snapshot();
$pageTitle = 'Кто сейчас на сайте';
$seoDescription = 'Кто прямо сейчас на ' . SITE_NAME . ' — пользователи, гости и роботы онлайн';
require_once __DIR__ . '/includes/header.php';

// Роботов группируем по имени (не по каждому запросу отдельно) — так же, как это делает XenForo.
$botGroups = [];
foreach ($snapshot['bots'] as $b) {
  $name = $b['name'];
  if (!isset($botGroups[$name])) $botGroups[$name] = ['name' => $name, 'count' => 0, 'path' => $b['path']];
  $botGroups[$name]['count']++;
}
?>
<div class="container">
  <h1 style="margin:24px 0 6px">🟢 Кто сейчас на сайте</h1>
  <p style="color:var(--text-dim);font-size:13px;max-width:640px">
    Активность за последние 5 минут. Считается автоматически по реальным просмотрам страниц.
  </p>

  <div class="stat-grid" style="margin-top:16px">
    <div class="stat-card"><div class="num"><?= count($snapshot['users']) ?></div><div class="lbl">Пользователей онлайн</div></div>
    <div class="stat-card"><div class="num"><?= count($snapshot['guests']) ?></div><div class="lbl">Гостей онлайн</div></div>
    <div class="stat-card"><div class="num"><?= array_sum(array_column($botGroups, 'count')) ?></div><div class="lbl">Роботов (поисковики и парсеры)</div></div>
  </div>

  <div class="form-card form-wide" style="margin:24px 0">
    <h3 style="margin-top:0">Пользователи</h3>
    <?php if (!$snapshot['users']): ?>
      <p style="color:var(--text-dim);font-size:13px">Сейчас на сайте нет вошедших пользователей.</p>
    <?php else: ?>
      <div style="overflow-x:auto"><table class="admin-table" style="width:100%">
        <thead><tr><th>Пользователь</th><th>Чем занят</th><th>Когда</th></tr></thead>
        <tbody>
          <?php foreach ($snapshot['users'] as $u): ?>
          <tr>
            <td><a href="/profile?username=<?= e($u['username']) ?>" style="color:var(--accent-2)"><?= e($u['username']) ?></a></td>
            <td><?= e(online_path_label($u['path'])) ?></td>
            <td style="color:var(--text-dim);font-size:12px"><?= e($u['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="form-card form-wide" style="margin:24px 0">
    <h3 style="margin-top:0">Роботы</h3>
    <?php if (!$botGroups): ?>
      <p style="color:var(--text-dim);font-size:13px">Сейчас роботов не замечено.</p>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($botGroups as $b): ?>
          <span class="status-pill status-pending" title="<?= e(online_path_label($b['path'])) ?>">🤖 <?= e($b['name']) ?><?= $b['count'] > 1 ? ' ×' . (int)$b['count'] : '' ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($isStaff): ?>
  <div class="form-card form-wide" style="margin:24px 0">
    <h3 style="margin-top:0">Гости — детально <span style="font-weight:normal;font-size:12px;color:var(--text-dim)">(видно только модераторам/админам)</span></h3>
    <?php if (!$snapshot['guests']): ?>
      <p style="color:var(--text-dim);font-size:13px">Гостей сейчас нет.</p>
    <?php else: ?>
      <div style="overflow-x:auto;max-height:420px;overflow-y:auto"><table class="admin-table" style="width:100%;min-width:600px">
        <thead><tr><th>IP</th><th>Чем занят</th><th>User-Agent</th><th>Когда</th></tr></thead>
        <tbody>
          <?php foreach ($snapshot['guests'] as $g): ?>
          <tr>
            <td><?= e($g['ip']) ?></td>
            <td><?= e(online_path_label($g['path'])) ?></td>
            <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-dim);font-size:11px" title="<?= e((string)$g['user_agent']) ?>"><?= e(mb_substr((string)$g['user_agent'], 0, 60)) ?></td>
            <td style="color:var(--text-dim);font-size:12px"><?= e($g['seen_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <p style="color:var(--text-dim);font-size:12px">Детальный список гостей (IP, устройство) виден модераторам и админам — публично показываем только общее число, чтобы не палить чужие данные.</p>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
