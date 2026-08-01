<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$stmt = db()->prepare("SELECT s.*, u.username AS owner_name FROM deployed_services s JOIN users u ON u.id = s.user_id WHERE s.slug = ? AND s.status = 'live'");
$stmt->execute([$slug]);
$service = $stmt->fetch();
if (!$service) { http_response_code(404); die('Сервис не найден или ещё не задеплоен'); }

// Защита от path traversal — slug генерируется только нашим кодом при деплое,
// но перепроверяем формат на всякий случай перед тем, как строить путь к файлам.
if (!preg_match('/^[a-z0-9-]+$/', $slug)) { http_response_code(400); die('Некорректный slug'); }

$frameSrc = '/services_data/' . $slug . '/index.html';

$pageTitle = e($service['name']);
require_once __DIR__ . '/includes/header.php';
?>
<div class="container">
  <h1><?= e($service['name']) ?></h1>
  <p style="color:var(--text-dim)">Автор: <a href="/profile.php?username=<?= urlencode($service['owner_name']) ?>">@<?= e($service['owner_name']) ?></a> · <?= e($service['description'] ?: '') ?></p>

  <div id="consentGate" style="border:1px solid var(--border,rgba(255,255,255,.1));border-radius:12px;padding:20px;max-width:520px">
    <p><b>Вы хотите открыть сервис «<?= e($service['name']) ?>»?</b></p>
    <p style="color:var(--text-dim);font-size:13px">
      Это сторонний сервис, созданный пользователем платформы, а не нами. Открывая его, вы можете передавать ему
      данные (например, если сервис запросит вход через ваш аккаунт). Мы не проверяем и не несём ответственность
      за содержимое сторонних сервисов.
    </p>
    <button class="btn btn-primary" onclick="openService()">Да, открыть на свой риск</button>
    <a href="/services.php" class="btn btn-outline">Отмена</a>
  </div>

  <div id="serviceFrame" style="display:none;margin-top:16px">
    <iframe src="" id="serviceIframe" style="width:100%;height:80vh;border:1px solid var(--border,rgba(255,255,255,.1));border-radius:12px" sandbox="allow-scripts allow-forms allow-popups"></iframe>
  </div>
</div>
<script>
function openService() {
  document.getElementById('consentGate').style.display = 'none';
  var frame = document.getElementById('serviceFrame');
  frame.style.display = 'block';
  document.getElementById('serviceIframe').src = <?= json_encode($frameSrc) ?>;
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
