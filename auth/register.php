<?php
$pageTitle = 'Регистрация';
require_once __DIR__ . '/../includes/header.php';

if ($__user) redirect('/dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $phoneRaw = trim($_POST['phone'] ?? '');

  if (mb_strlen($username) < 3 || mb_strlen($password) < 6 || !$email) {
    flash_set('error', 'Проверьте email, логин (мин. 3 симв.) и пароль (мин. 6 симв.)');
    redirect('/auth/register.php');
  }

  // Номер телефона обязателен — запасной способ входа, если забудете почту.
  // Без SMS-кода, но формат должен быть похож на настоящий номер, а не мусор.
  $phone = normalize_phone($phoneRaw);
  if (!$phone) {
    flash_set('error', 'Укажите настоящий номер телефона в международном формате (например, +79991234567) — это запасной способ входа, если забудете почту.');
    redirect('/auth/register.php');
  }

  $stmt = db()->prepare('SELECT id FROM users WHERE email = ? OR username = ? OR phone = ?');
  $stmt->execute([$email, $username, $phone]);
  if ($stmt->fetch()) {
    flash_set('error', 'Такой email, логин или номер телефона уже используется');
    redirect('/auth/register.php');
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);
  $stmt = db()->prepare('INSERT INTO users (email, username, password_hash, phone) VALUES (?, ?, ?, ?)');
  $stmt->execute([$email, $username, $hash, $phone]);

  flash_set('success', 'Регистрация успешна, теперь войдите');
  redirect('/auth/login.php');
}

$providers = db()->query('SELECT name, display_name, icon_url FROM oauth_providers WHERE enabled = 1')->fetchAll();
?>
<div class="container">
  <div class="form-card">
    <h2>Регистрация</h2>
    <form method="POST" action="/auth/register.php">
      <?= csrf_field() ?>
      <label>Email</label>
      <input type="email" name="email" required>
      <label>Логин</label>
      <input type="text" name="username" required minlength="3" maxlength="30">
      <label>Номер телефона</label>
      <input type="tel" name="phone" required placeholder="+79991234567">
      <p style="font-size:11.5px;color:var(--text-dim);margin:-8px 0 0">Настоящий номер — запасной способ входа, если забудете почту. SMS не отправляем.</p>
      <label style="margin-top:10px">Пароль</label>
      <input type="password" name="password" required minlength="6">
      <button class="btn btn-primary" style="margin-top:20px;width:100%" type="submit">Создать аккаунт</button>
    </form>
    <?php if ($providers): ?>
      <div class="oauth-row">
        <?php foreach ($providers as $p): ?>
          <a class="oauth-btn" href="/auth/oauth_start.php?provider=<?= e($p['name']) ?>">
            <?php if ($p['icon_url']): ?><img src="<?= e($p['icon_url']) ?>" alt=""><?php endif; ?>
            <?= e($p['display_name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p style="margin-top:18px;font-size:13px;color:var(--text-dim)">Уже есть аккаунт? <a href="/auth/login.php" style="color:var(--accent-2)">Войти</a></p>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
