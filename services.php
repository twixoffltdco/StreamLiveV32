<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/service_helpers.php';
$__user = current_user();

$stmt = db()->query(
  "SELECT oa.*, u.username FROM oauth_apps oa JOIN users u ON u.id = oa.owner_id
   WHERE oa.is_public_service = 1 AND oa.service_url IS NOT NULL ORDER BY oa.id DESC"
);
$oauthServices = $stmt->fetchAll();

deployed_services_ensure_schema();
$stmt = db()->query(
  "SELECT ds.*, u.username, u.is_verified, u.last_active_date FROM deployed_services ds JOIN users u ON u.id = ds.user_id
   WHERE ds.is_public = 1 AND ds.status = 'live' ORDER BY ds.id DESC"
);
$deployedServices = array_map('deployed_service_autostop', $stmt->fetchAll());

$pageTitle = 'Сервисы';
$seoDescription = 'Приложения и мини-сервисы, созданные сообществом ' . SITE_NAME;
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin:24px 0 6px">
    <h1 style="margin:0">🧩 Сервисы</h1>
    <div style="display:flex;gap:8px">
      <a href="/developers.php" class="btn btn-outline btn-sm">OAuth-приложение</a>
      <a href="/github_connect.php" class="btn btn-outline btn-sm">Выложить из GitHub</a>
    </div>
  </div>
  <p style="color:var(--text-dim);font-size:13px;margin-bottom:20px">Приложения и мини-сайты, созданные сообществом. Каждый запуск спрашивает подтверждение — мы не открываем сторонний сервис без вашего согласия.</p>

  <div class="profile-grid">
    <?php foreach ($oauthServices as $s): ?>
      <div class="profile-grid-item" style="cursor:pointer" onclick="launchOauthService('<?= e(addslashes($s['name'])) ?>', '<?= e(addslashes($s['description'] ?? '')) ?>', '<?= e($s['client_id']) ?>')">
        <img src="<?= e($s['logo_url'] ?: '/assets/img/avatar-placeholder.png') ?>" alt="" onerror="this.style.display='none'">
        <div class="profile-grid-caption">
          <b><?= e($s['name']) ?></b>
          <span style="font-size:11px;color:var(--text-dim)">от <?= e($s['username']) ?> · вход через аккаунт</span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="services-grid" style="margin-top:16px">
    <?php foreach ($deployedServices as $s): ?>
      <div class="profile-grid-item service-card <?= !empty($s['suspended']) ? 'service-card-suspended' : '' ?>" style="cursor:pointer" <?php if (empty($s['suspended'])): ?>onclick="launchDeployedService('<?= e(addslashes($s['name'])) ?>', '<?= e(addslashes($s['description'] ?? '')) ?>', '<?= e($s['slug']) ?>')"<?php endif; ?>>
        <div class="service-preview"><iframe src="<?= e(deployed_service_preview_url($s['slug'])) ?>" loading="lazy" sandbox="allow-scripts allow-forms"></iframe></div>
        <div class="profile-grid-caption">
          <b><?= e($s['name']) ?></b>
          <span style="font-size:11px;color:var(--text-dim)">от <?= e($s['username']) ?> · <?= e($s['repo_full_name']) ?></span>
          <?php if (!empty($s['suspended'])): ?><span class="status-pill status-rejected">услуга окончена</span><small><?= e($s['suspended_reason'] ?: 'Продлите подписку') ?></small><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
    <?php if (!$oauthServices && !$deployedServices): ?>
      <p style="color:var(--text-dim)">Пока нет опубликованных сервисов — <a href="/github_connect.php" style="color:var(--accent-2)">выложите первый из GitHub</a>.</p>
    <?php endif; ?>
  </div>
</div>

<div id="service-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:2000;align-items:center;justify-content:center;padding:16px">
  <div style="max-width:400px;width:100%;background:var(--card);border:1px solid var(--border);border-radius:16px;padding:26px;text-align:center">
    <h3 id="sm-title" style="margin:0 0 8px"></h3>
    <p id="sm-desc" style="color:var(--text-dim);font-size:13px"></p>
    <div id="sm-warning" style="background:rgba(255,77,79,.1);border:1px solid rgba(255,77,79,.3);border-radius:10px;padding:12px;font-size:12px;color:#ff9b9d;margin:14px 0;text-align:left"></div>
    <div style="display:flex;gap:10px;margin-top:10px">
      <button class="btn btn-outline" style="flex:1" onclick="document.getElementById('service-modal').style.display='none'">Отмена</button>
      <button class="btn btn-primary" style="flex:1" id="sm-confirm">Да, согласен на риск</button>
    </div>
  </div>
</div>
<script>
function showModal(title, desc, warning, onConfirm) {
  document.getElementById('sm-title').textContent = title;
  document.getElementById('sm-desc').textContent = desc || 'Стороннее приложение сообщества.';
  document.getElementById('sm-warning').textContent = warning;
  document.getElementById('service-modal').style.display = 'flex';
  document.getElementById('sm-confirm').onclick = function () {
    onConfirm();
    document.getElementById('service-modal').style.display = 'none';
  };
}
function launchOauthService(name, desc, clientId) {
  showModal('Открыть «' + name + '»?', desc, '⚠️ Вы передаёте сервису логин и аватар. Открывайте, только если доверяете разработчику.', function () {
    window.open('/oauth2/authorize.php?client_id=' + encodeURIComponent(clientId), '_blank');
  });
}
function launchDeployedService(name, desc, slug) {
  showModal('Открыть «' + name + '»?', desc, '⚠️ Это код стороннего пользователя, автоматически выложенный из его GitHub-репозитория. Мы не проверяем его содержимое.', function () {
    window.open('/s.php?slug=' + encodeURIComponent(slug), '_blank');
  });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
