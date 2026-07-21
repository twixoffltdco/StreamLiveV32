<?php
$pageTitle = 'Вход';
require_once __DIR__ . '/../includes/header.php';

if (isset($_GET['next'])) {
  $_SESSION['login_next'] = normalize_auth_redirect_target($_GET['next'], '/dashboard.php');
}

if ($__user) redirect(safe_after_login_redirect());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $login = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  // ---------- ПОИСК ПОЛЬЗОВАТЕЛЯ (улучшенный) ----------
  $user = null;

  // 1. Пробуем нормализованный телефон (через функцию normalize_phone)
  $normalizedPhone = normalize_phone($login);
  if ($normalizedPhone) {
    $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$normalizedPhone]);
    $user = $stmt->fetch();
  }

  // 2. Если не найден, и ввод похож на номер телефона – пробуем очистить от мусора
  if (!$user && preg_match('/^[\+\d\s\-\(\)]+$/', $login)) {
    // Удаляем всё, кроме цифр и '+'
    $clean = preg_replace('/[^0-9+]/', '', $login);
    // Если нет '+', добавляем его (предполагаем, что в БД хранится с '+')
    if (strpos($clean, '+') !== 0) {
      $clean = '+' . ltrim($clean, '+');
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$clean]);
    $user = $stmt->fetch();
  }

  // 3. Иначе – ищем по email
  if (!$user) {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$login]);
    $user = $stmt->fetch();
  }
  // ----------------------------------------------------

  if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
    flash_set('error', 'Неверный email/телефон или пароль');
    redirect('/auth/login.php');
  }
  if ($user['is_banned']) {
    flash_set('error', 'Аккаунт заблокирован');
    redirect('/auth/login.php');
  }

  $_SESSION['login_next'] = safe_after_login_redirect();

  if (get_setting('force_2fa_enabled', '0') === '1') {
    $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
    $token = make_2fa_token((int)$user['id']);
    redirect(($user['totp_enabled'] ? '/auth/2fa_verify.php' : '/auth/2fa_setup.php') . '?t=' . urlencode($token));
  }

  login_user((int)$user['id']);
  $next = safe_after_login_redirect();
  unset($_SESSION['login_next']);
  redirect($next);
}

$providers = db()->query('SELECT name, display_name, icon_url FROM oauth_providers WHERE enabled = 1')->fetchAll();
?>
<div class="container">
  <div class="form-card">
    <h2>Вход в аккаунт</h2>
    <form method="POST" action="/auth/login.php<?= !empty($_GET['next']) ? '?next=' . rawurlencode((string)$_GET['next']) : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($_GET['next'] ?? '') ?>">
      <label>Email или номер телефона</label>
      <input type="text" name="email" required placeholder="you@mail.com или +79991234567">
      <label>Пароль</label>
      <input type="password" name="password" required>
      <button class="btn btn-primary" style="margin-top:20px;width:100%" type="submit">Войти</button>
    </form>
    <?php if ($providers): ?>
      <div class="oauth-row">
        <?php foreach ($providers as $p): ?>
          <a class="oauth-btn" href="/auth/oauth_start.php?provider=<?= e($p['name']) ?><?= !empty($_GET['next']) ? '&next=' . rawurlencode((string)$_GET['next']) : '' ?>">
            <?php if ($p['icon_url']): ?><img src="<?= e($p['icon_url']) ?>" alt=""><?php endif; ?>
            Войти через <?= e($p['display_name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p style="margin-top:18px;font-size:13px;color:var(--text-dim)">Нет аккаунта? <a href="/auth/register.php" style="color:var(--accent-2)">Зарегистрироваться</a></p>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>