<?php
$pageTitle = 'Регистрация';
require_once __DIR__ . '/../includes/header.php';
if (is_file(__DIR__ . '/../includes/partners.php')) {
  require_once __DIR__ . '/../includes/partners.php';
  try { partners_remember_ref_from_request(); } catch (Throwable $e) {}
}

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

  // Телефон НЕ обязателен при регистрации — можно привязать позже на /auth/add_phone.php
  $phone = null;
  if ($phoneRaw !== '') {
    $phone = normalize_phone($phoneRaw);
    if (!$phone) {
      flash_set('error', 'Номер телефона в международном формате, например +79991234567. Или оставьте поле пустым и привяжите позже.');
      redirect('/auth/register.php');
    }
    // опциональная проверка через API (если задан ключ в settings)
    if (function_exists('phone_verify_external') && !phone_verify_external($phone)) {
      flash_set('error', 'Номер не прошёл проверку. Укажите реальный номер или оставьте поле пустым.');
      redirect('/auth/register.php');
    }
  }

  if ($phone) {
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? OR username = ? OR phone = ?');
    $stmt->execute([$email, $username, $phone]);
  } else {
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
    $stmt->execute([$email, $username]);
  }
  if ($stmt->fetch()) {
    flash_set('error', 'Такой email, логин или номер телефона уже используется');
    redirect('/auth/register.php');
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);
  $stmt = db()->prepare('INSERT INTO users (email, username, password_hash, phone) VALUES (?, ?, ?, ?)');
  $stmt->execute([$email, $username, $hash, $phone]);
  $newId = (int)db()->lastInsertId();
  if ($newId > 0 && function_exists('partners_capture_referral')) {
    try { partners_capture_referral($newId); } catch (Throwable $e) {}
  }

  flash_set('success', $phone
    ? 'Регистрация успешна, теперь войдите'
    : 'Регистрация успешна. После входа можно привязать номер телефона в профиле.');
  redirect('/auth/login.php');
}

$providers = [];
try {
  $providers = db()->query('SELECT name, display_name, icon_url FROM oauth_providers WHERE enabled = 1')->fetchAll() ?: [];
} catch (Throwable $e) {}
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
      <label>Номер телефона <span style="font-weight:400;color:var(--text-dim)">(необязательно)</span></label>
      <input type="tel" name="phone" placeholder="+79991234567">
      <p style="font-size:11.5px;color:var(--text-dim);margin:-8px 0 0">Можно зарегистрироваться без номера и привязать его позже. Формат: +79991234567.</p>
      <label style="margin-top:10px">Пароль</label>
      <input type="password" name="password" required minlength="6">
      <button class="btn btn-primary" type="submit" style="margin-top:16px;width:100%">Зарегистрироваться</button>
    </form>
    <?php if ($providers): ?>
      <p style="margin-top:16px;color:var(--text-dim);font-size:13px">Или через OAuth</p>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
