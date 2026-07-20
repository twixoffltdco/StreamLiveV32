<?php
// StreamLive install wizard (PHP).
// Шаги: 1) приветствие  2) БД + название/URL проекта  3) аккаунт администратора  4) готово
session_start();

$configFile = __DIR__ . '/../config/config.php';
$lockFile = __DIR__ . '/installed.lock';

if (file_exists($lockFile)) {
  http_response_code(200);
  echo '<link rel="stylesheet" href="/assets/css/style.css"><body style="background:#0b0b10;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh"><div style="text-align:center"><h2>StreamLive уже установлен</h2><p style="color:#9a9aac">Чтобы переустановить — удалите install/installed.lock и config/config.php на сервере.</p><a href="/" style="color:#25f4ee">На главную</a></div></body>';
  exit;
}

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$step = $_POST['step'] ?? $_GET['step'] ?? '1';
$error = null;
$values = $_POST;

$requirements = [
  'PDO MySQL (pdo_mysql)' => extension_loaded('pdo_mysql'),
  'mbstring' => extension_loaded('mbstring'),
  'cURL' => extension_loaded('curl'),
  'Папка config/ доступна на запись' => is_writable(__DIR__ . '/../config'),
  'Папка install/ доступна на запись' => is_writable(__DIR__),
];
$requirementsOk = !in_array(false, $requirements, true);

if (!$requirementsOk) {
  $step = '1';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
  // Проверка подключения к MySQL и создание базы данных
  $host = trim($_POST['db_host']);
  $port = trim($_POST['db_port']) ?: '3306';
  $name = trim($_POST['db_name']);
  $user = trim($_POST['db_user']);
  $pass = $_POST['db_password'] ?? '';

  try {
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Если база уже существовала с другой кодировкой (частый случай на бесплатных хостингах,
    // где БД создаётся заранее панелью управления) — принудительно переключаем её на utf8mb4.
    try { $pdo->exec("ALTER DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Exception $ignored) {}
    $step = '3';
  } catch (Exception $ex) {
    $error = 'Не удалось подключиться к базе данных: ' . $ex->getMessage();
    $step = '2';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '3') {
  $adminEmail = trim($_POST['admin_email']);
  $adminUsername = trim($_POST['admin_username']);
  $adminPassword = $_POST['admin_password'] ?? '';

  if (!$adminEmail || !$adminUsername || strlen($adminPassword) < 6) {
    $error = 'Заполните все поля, пароль минимум 6 символов';
    $step = '3';
  } else {
    try {
      $host = trim($_POST['db_host']);
      $port = trim($_POST['db_port']) ?: '3306';
      $name = trim($_POST['db_name']);
      $user = trim($_POST['db_user']);
      $pass = $_POST['db_password'] ?? '';
      $siteName = trim($_POST['site_name']);
      $siteUrl = rtrim(trim($_POST['site_url']), '/');

      $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
      ]);

      // Автозаливка схемы
      $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
      $pdo->exec($schema);

      // Создание администратора
      $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role) VALUES (?, ?, ?, "admin")');
      $stmt->execute([$adminEmail, $adminUsername, $hash]);

      // Пресеты OAuth-провайдеров (выключены, ключи добавляются в админке)
      $providers = [
        ['google', 'Google', 'https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'https://www.googleapis.com/oauth2/v3/userinfo', 'openid email profile'],
        ['vk', 'VK', 'https://oauth.vk.com/authorize', 'https://oauth.vk.com/access_token', 'https://api.vk.com/method/users.get', 'email'],
        ['yandex', 'Яндекс', 'https://oauth.yandex.ru/authorize', 'https://oauth.yandex.ru/token', 'https://login.yandex.ru/info', 'login:email login:info'],
      ];
      $stmt = $pdo->prepare('INSERT INTO oauth_providers (name, display_name, auth_url, token_url, profile_url, scope, enabled) VALUES (?, ?, ?, ?, ?, ?, 0)');
      foreach ($providers as $p) { $stmt->execute($p); }

      $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES ("site_name", ?), ("site_url", ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
      $stmt->execute([$siteName, $siteUrl]);

      // Запись config.php
      $configContent = "<?php\n"
        . "define('DB_HOST', " . var_export($host, true) . ");\n"
        . "define('DB_PORT', " . var_export($port, true) . ");\n"
        . "define('DB_NAME', " . var_export($name, true) . ");\n"
        . "define('DB_USER', " . var_export($user, true) . ");\n"
        . "define('DB_PASS', " . var_export($pass, true) . ");\n\n"
        . "define('SITE_NAME', " . var_export($siteName, true) . ");\n"
        . "define('SITE_URL', " . var_export($siteUrl, true) . ");\n\n"
        . "define('INSTALLED', true);\n";
      file_put_contents($configFile, $configContent);
      file_put_contents($lockFile, date('c'));

      $step = '4';
      $doneSiteUrl = $siteUrl;
    } catch (Exception $ex) {
      $error = 'Ошибка установки: ' . $ex->getMessage();
      $step = '3';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Установка StreamLive</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    .install-wrap { max-width: 560px; margin: 60px auto; padding: 0 20px; }
    .steps { display: flex; gap: 8px; margin-bottom: 24px; }
    .step-dot { flex: 1; height: 4px; border-radius: 4px; background: var(--border); }
    .step-dot.done { background: var(--accent-grad); }
  </style>
</head>
<body>
<div class="install-wrap">

<?php if ($step === '1'): ?>
  <div class="steps"><div class="step-dot done"></div><div class="step-dot"></div><div class="step-dot"></div></div>
  <div class="form-card" style="margin:0">
    <h2 class="brand" style="font-size:26px">StreamLive — установка</h2>
    <p style="color:var(--text-dim);font-size:14px">Мастер установки настроит базу данных, создаст таблицы и вашего администратора.</p>
    <ul style="color:var(--text-dim);font-size:13px;line-height:1.9">
      <?php foreach ($requirements as $label => $ok): ?>
        <li><?= $ok ? '<span style="color:var(--ok)">✓</span>' : '<span style="color:var(--danger)">✗</span>' ?> <?= e($label) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$requirementsOk): ?>
      <div class="alert alert-error">Не все требования выполнены. Установите недостающие расширения PHP (например <code>apt install php-mysql php-mbstring php-curl</code>) или выдайте права на запись, затем обновите страницу.</div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="step" value="2">
      <button class="btn btn-primary" style="width:100%;margin-top:16px" type="submit" <?= $requirementsOk ? '' : 'disabled' ?>>Начать установку</button>
    </form>
  </div>

<?php elseif ($step === '2'): ?>
  <div class="steps"><div class="step-dot done"></div><div class="step-dot done"></div><div class="step-dot"></div></div>
  <div class="form-card" style="margin:0">
    <h2>Проект и база данных</h2>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="step" value="2">
      <label>Название проекта</label>
      <input type="text" name="site_name" value="<?= e($values['site_name'] ?? 'StreamLive') ?>" required>
      <label>Ссылка на сайт (URL)</label>
      <input type="url" name="site_url" value="<?= e($values['site_url'] ?? 'http://localhost') ?>" required>

      <hr style="border-color:var(--border);margin:20px 0">

      <label>Хост MySQL</label>
      <input type="text" name="db_host" value="<?= e($values['db_host'] ?? 'localhost') ?>" required>
      <label>Порт</label>
      <input type="text" name="db_port" value="<?= e($values['db_port'] ?? '3306') ?>">
      <label>Имя базы данных</label>
      <input type="text" name="db_name" value="<?= e($values['db_name'] ?? 'streamlive') ?>" required>
      <label>Пользователь MySQL</label>
      <input type="text" name="db_user" value="<?= e($values['db_user'] ?? '') ?>" required>
      <label>Пароль MySQL</label>
      <input type="password" name="db_password" value="<?= e($values['db_password'] ?? '') ?>">

      <button class="btn btn-primary" style="width:100%;margin-top:20px" type="submit">Проверить подключение и продолжить</button>
    </form>
  </div>

<?php elseif ($step === '3'): ?>
  <div class="steps"><div class="step-dot done"></div><div class="step-dot done"></div><div class="step-dot done"></div></div>
  <div class="form-card" style="margin:0">
    <h2>Аккаунт администратора</h2>
    <p style="color:var(--text-dim);font-size:13px">После сохранения база данных будет создана автоматически, а этот аккаунт получит полные права администратора.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="step" value="3">
      <input type="hidden" name="site_name" value="<?= e($values['site_name'] ?? '') ?>">
      <input type="hidden" name="site_url" value="<?= e($values['site_url'] ?? '') ?>">
      <input type="hidden" name="db_host" value="<?= e($values['db_host'] ?? '') ?>">
      <input type="hidden" name="db_port" value="<?= e($values['db_port'] ?? '') ?>">
      <input type="hidden" name="db_name" value="<?= e($values['db_name'] ?? '') ?>">
      <input type="hidden" name="db_user" value="<?= e($values['db_user'] ?? '') ?>">
      <input type="hidden" name="db_password" value="<?= e($values['db_password'] ?? '') ?>">

      <label>Email</label>
      <input type="email" name="admin_email" required>
      <label>Логин</label>
      <input type="text" name="admin_username" value="admin" required>
      <label>Пароль</label>
      <input type="password" name="admin_password" required minlength="6">
      <button class="btn btn-primary" style="width:100%;margin-top:20px" type="submit">Завершить установку</button>
    </form>
  </div>

<?php elseif ($step === '4'): ?>
  <div class="form-card" style="margin:0;text-align:center">
    <h2 style="color:var(--ok)">✓ Установка завершена</h2>
    <p style="color:var(--text-dim);font-size:14px">База данных создана, таблицы залиты, администратор создан.</p>
    <p style="color:var(--text-dim);font-size:13px">Откройте <code><?= e($doneSiteUrl ?? '') ?></code> и войдите под созданным аккаунтом.</p>
    <a href="/auth/login.php" class="btn btn-primary" style="width:100%;margin-top:10px">Перейти ко входу</a>
  </div>
<?php endif; ?>

</div>
</body>
</html>
