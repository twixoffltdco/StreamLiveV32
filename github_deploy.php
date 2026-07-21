<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/github_api.php';
$__user = require_login();

$stmt = db()->prepare('SELECT * FROM github_connections WHERE user_id = ?');
$stmt->execute([$__user['id']]);
$connection = $stmt->fetch();
if (!$connection) { redirect('/github_connect.php'); }

[$__cipher, $__iv] = array_pad(explode(':', $connection['access_token_enc'], 2), 2, null);
$token = decrypt_secret($__cipher, $__iv);
if ($token === null) {
  flash_set('error', 'Не удалось расшифровать сохранённый токен (возможно, сменился ключ шифрования сайта) — переподключите GitHub заново');
  redirect('/github_connect.php');
}
$error = null;

// Каталог, куда распаковываются статические сервисы. Должен быть доступен по вебу
// (например https://твой-домен/services_data/{slug}/) — на InfinityFree это обычная
// папка в htdocs, права 755/777 в зависимости от хостинга.
// Бесплатный хостинг часто режет выполнение скрипта по времени/памяти — увеличиваем,
// что можем (если хостинг жёстко режет на уровне веб-сервера, это не поможет, но
// попробовать стоит, это бесплатно).
@set_time_limit(120);
@ini_set('memory_limit', '256M');

define('SERVICES_DIR', __DIR__ . '/services_data');

// Лимит на количество сервисов: 1 без галочки "доверенный" (is_verified), безлимит с ней.
// Считаем только НЕ приостановленные модератором сервисы — заблокированный слот не должен
// мешать задеплоить новый.
$__stmt = db()->prepare("SELECT COUNT(*) FROM deployed_services WHERE user_id = ? AND status = 'live' AND suspended = 0");
$__stmt->execute([$__user['id']]);
$__activeServicesCount = (int)$__stmt->fetchColumn();
$__maxServices = !empty($__user['is_verified']) ? PHP_INT_MAX : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deploy' && $__activeServicesCount >= $__maxServices) {
  flash_set('error', 'Лимит: 1 активный сервис на аккаунт без галочки "доверенный". Удалите текущий или запросите верификацию у модератора для безлимита.');
  redirect('/github_deploy.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deploy') {
  csrf_verify();
  $repoFullName = trim($_POST['repo'] ?? '');
  $branch = trim($_POST['branch'] ?? 'main');
  $name = trim($_POST['name'] ?? '');
  $description = trim($_POST['description'] ?? '');

  if (!extension_loaded('zip')) {
    $error = 'На сервере не включено расширение PHP ZipArchive — без него распаковка репозитория невозможна. Это включается в панели хостинга (обычно "Select PHP Extensions") или через тикет в поддержку.';
  } elseif (!preg_match('#^[\w.-]+/[\w.-]+$#', $repoFullName) || $name === '') {
    $error = 'Заполните название и выберите корректный репозиторий';
  } else {
    [$owner, $repo] = explode('/', $repoFullName, 2);
    $slug = strtolower($repo) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);

    $serviceId = null;
    try {
      $stmt = db()->prepare(
        'INSERT INTO deployed_services (user_id, name, description, repo_full_name, branch, slug, status)
         VALUES (?, ?, ?, ?, ?, ?, "deploying")'
      );
      $stmt->execute([$__user['id'], $name, $description, $repoFullName, $branch, $slug]);
      $serviceId = (int)db()->lastInsertId();

      // Синхронный деплой прямо в этом запросе — на бесплатном хостинге нет очереди
      // фоновых воркеров. Подходит для статических сайтов (HTML/CSS/JS, собранный
      // React/Vue билд, закоммиченный в репозиторий). Проекты, которым нужен свой
      // npm run build на сервере, так не задеплоятся — см. README про это ограничение.
      // Проверяем размер репозитория заранее через GitHub API (поле size, в килобайтах) —
      // на бесплатном хостинге лучше сразу сказать "слишком большой", чем упасть в 500
      // от таймаута/памяти на середине скачивания.
      $repoInfo = github_api_get($token, "/repos/{$repoFullName}");
      if ($repoInfo && isset($repoInfo['size']) && $repoInfo['size'] > 51200) { // > 50 МБ
        throw new RuntimeException('Репозиторий больше 50 МБ (' . round($repoInfo['size'] / 1024) . ' МБ) — на бесплатном хостинге такой объём не успевает скачаться и распаковаться за отведённое время выполнения скрипта. Нужен репозиторий меньше, либо VPS/хостинг без таких ограничений.');
      }

      $zipPath = github_download_repo_zip($owner, $repo, $branch);
      if (!$zipPath) throw new RuntimeException('Не удалось скачать архив ветки. Проверьте название ветки и права токена.');

      $zip = new ZipArchive();
      if ($zip->open($zipPath) !== true) throw new RuntimeException('Архив повреждён или не является zip');

      if (!is_dir(SERVICES_DIR) && !mkdir(SERVICES_DIR, 0755, true) && !is_dir(SERVICES_DIR)) {
        throw new RuntimeException('Не удалось создать папку services_data/ — проверьте права на запись в корне проекта (обычно 755, на некоторых хостингах нужно 777)');
      }
      $targetDir = SERVICES_DIR . '/' . $slug;
      if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Не удалось создать папку сервиса — проверьте права на запись');
      }
      $zip->extractTo($targetDir);
      $zip->close();
      @unlink($zipPath);

      // GitHub упаковывает архив в одну обёрточную папку вида "repo-branch/" — поднимаем
      // её содержимое на уровень выше, чтобы index.html лежал сразу в корне сервиса.
      $entries = array_values(array_diff(scandir($targetDir), ['.', '..']));
      if (count($entries) === 1 && is_dir($targetDir . '/' . $entries[0])) {
        $wrapper = $targetDir . '/' . $entries[0];
        foreach (array_diff(scandir($wrapper), ['.', '..']) as $item) {
          rename($wrapper . '/' . $item, $targetDir . '/' . $item);
        }
        rmdir($wrapper);
      }

      if (!file_exists($targetDir . '/index.html')) {
        throw new RuntimeException('В репозитории не найден index.html в корне (или в единственной корневой папке). Задеплоить можно только уже собранный статический сайт.');
      }

      db()->prepare("UPDATE deployed_services SET status='live', deployed_at=NOW() WHERE id=?")->execute([$serviceId]);
      flash_set('success', 'Сервис выложен: /s.php?slug=' . $slug);
      redirect('/s.php?slug=' . $slug);
    } catch (\Throwable $e) {
      if ($serviceId) {
        db()->prepare("UPDATE deployed_services SET status='failed', error_message=? WHERE id=?")->execute([$e->getMessage(), $serviceId]);
      }
      $error = 'Деплой не удался: ' . $e->getMessage();
    }
  }
}

$repos = github_list_repos($token) ?: [];

$stmt = db()->prepare('SELECT * FROM deployed_services WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$__user['id']]);
$myServices = $stmt->fetchAll();

$pageTitle = 'Выложить сервис из GitHub';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:720px">
  <h1>Выложить сервис из GitHub</h1>
  <p style="color:var(--text-dim)">Подключён: @<?= e($connection['github_username']) ?> · <a href="/github_connect.php">сменить аккаунт</a></p>

  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <form method="POST" style="margin:20px 0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="deploy">

    <label>Репозиторий</label>
    <select name="repo" required style="width:100%;padding:10px;margin:8px 0">
      <?php foreach ($repos as $r): ?>
        <option value="<?= e($r['full_name']) ?>" data-branch="<?= e($r['default_branch'] ?? 'main') ?>"><?= e($r['full_name']) ?> <?= $r['private'] ? '(приватный)' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$repos): ?><p style="color:var(--danger)">Репозитории не загрузились — проверьте права токена.</p><?php endif; ?>

    <label>Ветка</label>
    <input type="text" name="branch" id="branchInput" value="main" style="width:100%;padding:10px;margin:8px 0">

    <label>Название сервиса</label>
    <input type="text" name="name" required maxlength="150" style="width:100%;padding:10px;margin:8px 0">

    <label>Описание (покажем в каталоге «Сервисы»)</label>
    <textarea name="description" maxlength="500" rows="2" style="width:100%;padding:10px;margin:8px 0"></textarea>

    <button type="submit" class="btn btn-primary">Задеплоить</button>
  </form>
  <p style="color:var(--text-dim);font-size:13px">
    Поддерживаются статические сайты — HTML/CSS/JS в корне репозитория (или уже собранный React/Vue билд, закоммиченный в репо).
    Проектам, которым нужна сборка на сервере (npm run build на нашей стороне), это пока не подходит.
  </p>

  <h2 style="margin-top:32px">Мои сервисы</h2>
  <?php foreach ($myServices as $s): ?>
    <div class="card" style="padding:14px;margin-bottom:10px">
      <b><?= e($s['name']) ?></b>
      <span class="status-pill status-<?= $s['status'] === 'live' ? 'approved' : ($s['status'] === 'failed' ? 'rejected' : 'pending') ?>"><?= e($s['status']) ?></span>
      <div style="color:var(--text-dim);font-size:13px"><?= e($s['repo_full_name']) ?> @ <?= e($s['branch']) ?></div>
      <?php if ($s['status'] === 'live'): ?><a href="/s.php?slug=<?= e($s['slug']) ?>">/s.php?slug=<?= e($s['slug']) ?> →</a><?php endif; ?>
      <?php if ($s['status'] === 'failed'): ?><div style="color:var(--danger);font-size:12px"><?= e($s['error_message']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<script>
document.querySelector('select[name="repo"]').addEventListener('change', function () {
  document.getElementById('branchInput').value = this.selectedOptions[0].dataset.branch || 'main';
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
