<?php
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/auth.php';

$providerName = $_GET['provider'] ?? '';
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

$client = OAuthClient::find($providerName);
if (!$client || !$code) {
  flash_set('error', 'Ошибка авторизации через ' . e($providerName));
  redirect('/auth/login.php');
}

if (empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
  flash_set('error', 'Сессия авторизации устарела, попробуйте снова');
  redirect('/auth/login.php');
}

$tokenData = $client->exchangeCode($code);
if (!$tokenData || empty($tokenData['access_token'])) {
  flash_set('error', 'Не удалось получить токен доступа от ' . e($providerName));
  redirect('/auth/login.php');
}

$profileRaw = $client->fetchProfile($tokenData['access_token']);
$profile = OAuthClient::normalizeProfile($profileRaw ?? []);

// Если провайдер вообще не вернул id профиля — это ошибка ответа API, а не повод
// плодить новый аккаунт со случайным id. Прерываем с понятной ошибкой.
if ($profile['id'] === null) {
  flash_set('error', 'Не удалось получить данные профиля от ' . e($providerName) . '. Попробуйте ещё раз.');
  redirect('/auth/login.php');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE oauth_provider = ? AND oauth_id = ?');
$stmt->execute([$providerName, $profile['id']]);
$user = $stmt->fetch();

// Запасной путь: тот же провайдер мог однажды вернуть id в другом формате (или это
// был баг в старой версии, из-за которого писался случайный oauth_id) — в этом случае
// пробуем найти существующий аккаунт по email и просто дозаписываем ему правильный oauth_id,
// вместо того чтобы плодить дубликат.
if (!$user && $profile['email']) {
  $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
  $stmt->execute([$profile['email']]);
  $byEmail = $stmt->fetch();
  if ($byEmail) {
    $pdo->prepare('UPDATE users SET oauth_provider = ?, oauth_id = ? WHERE id = ?')
      ->execute([$providerName, $profile['id'], $byEmail['id']]);
    $user = $byEmail;
  }
}

if (!$user) {
  $username = $providerName . '_' . substr($profile['id'], 0, 20);
  // На случай если такой логин уже занят — добавляем случайный хвост, а не падаем на UNIQUE constraint.
  $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
  $stmt->execute([$username]);
  if ($stmt->fetch()) $username .= '_' . substr(bin2hex(random_bytes(3)), 0, 5);

  $stmt = $pdo->prepare('INSERT INTO users (email, username, oauth_provider, oauth_id, avatar, oauth_access_token) VALUES (?, ?, ?, ?, ?, ?)');
  $stmt->execute([$profile['email'], $username, $providerName, $profile['id'], $profile['avatar'], $tokenData['access_token']]);
  $userId = (int)$pdo->lastInsertId();
} else {
  if ($user['is_banned']) {
    flash_set('error', 'Аккаунт заблокирован');
    redirect('/auth/login.php');
  }
  $userId = (int)$user['id'];
  // Токен обновляем при каждом входе — нужен свежий для "Возможно, вы знакомы" (друзья ВК).
  $pdo->prepare('UPDATE users SET oauth_access_token = ? WHERE id = ?')->execute([$tokenData['access_token'], $userId]);
}

$stmt = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ?');
$stmt->execute([$userId]);
$totpEnabled = (bool)$stmt->fetchColumn();

if (get_setting('force_2fa_enabled', '0') === '1') {
  $_SESSION['pending_2fa_user_id'] = $userId;
  $token = make_2fa_token($userId);
  redirect(($totpEnabled ? '/auth/2fa_verify.php' : '/auth/2fa_setup.php') . '?t=' . urlencode($token));
}

login_user($userId);
redirect('/dashboard.php');
