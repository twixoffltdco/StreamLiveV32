<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
  @session_start();
}

$providerName = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['provider'] ?? ''));
$code = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$error = (string)($_GET['error'] ?? '');

if ($error !== '') {
  flash_set('error', 'Отказ авторизации: ' . $error);
  redirect('/auth/login.php');
}

$client = OAuthClient::find($providerName);
if (!$client || $code === '') {
  flash_set('error', 'Ошибка авторизации через ' . ($providerName !== '' ? $providerName : 'провайдера'));
  redirect('/auth/login.php');
}

if (empty($_SESSION['oauth_state']) || !hash_equals((string)$_SESSION['oauth_state'], $state)) {
  flash_set('error', 'Сессия авторизации устарела, попробуйте снова');
  redirect('/auth/login.php');
}
unset($_SESSION['oauth_state']);

$tokenData = $client->exchangeCode($code);
if (!$tokenData || empty($tokenData['access_token'])) {
  flash_set('error', 'Не удалось получить токен от ' . $providerName . '. Проверьте Client ID/Secret и Redirect URI в админке.');
  redirect('/auth/login.php');
}

$rawProfile = $client->fetchProfile((string)$tokenData['access_token']);
if (!$rawProfile) {
  flash_set('error', 'Не удалось получить профиль от ' . $providerName);
  redirect('/auth/login.php');
}

$profile = OAuthClient::normalizeProfile($rawProfile, $providerName);
if (empty($profile['id'])) {
  // VK иногда отдаёт email в token response
  if (!empty($tokenData['user_id'])) {
    $profile['id'] = (string)$tokenData['user_id'];
  }
  if (!empty($tokenData['email']) && empty($profile['email'])) {
    $profile['email'] = (string)$tokenData['email'];
  }
}
if (empty($profile['id'])) {
  flash_set('error', 'Провайдер не вернул ID пользователя. Проверьте scope и Profile URL.');
  redirect('/auth/login.php');
}

$pdo = db();

// Уже вошёл — только привязка, без нового аккаунта
$current = function_exists('current_user') ? current_user() : null;
if ($current && !empty($current['id'])) {
  try {
    $pdo->prepare('UPDATE users SET oauth_provider = ?, oauth_id = ?, oauth_access_token = ? WHERE id = ?')
      ->execute([$providerName, $profile['id'], $tokenData['access_token'], (int)$current['id']]);
    if (!empty($profile['avatar'])) {
      try {
        $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ? AND (avatar IS NULL OR avatar = "")')
          ->execute([$profile['avatar'], (int)$current['id']]);
      } catch (Throwable $e) {}
    }
    flash_set('success', 'Соцсеть «' . $providerName . '» привязана к аккаунту');
    redirect('/profile.php?username=' . rawurlencode((string)$current['username']));
  } catch (Throwable $e) {
    flash_set('error', 'Не удалось привязать: возможно, этот ' . $providerName . ' уже у другого аккаунта');
    redirect('/dashboard.php');
  }
}

// Поиск существующего по oauth_id
$user = null;
try {
  $stmt = $pdo->prepare('SELECT * FROM users WHERE oauth_provider = ? AND oauth_id = ? LIMIT 1');
  $stmt->execute([$providerName, $profile['id']]);
  $user = $stmt->fetch() ?: null;
} catch (Throwable $e) {}

// По email — привязка, без дубля
if (!$user && !empty($profile['email'])) {
  try {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$profile['email']]);
    $byEmail = $stmt->fetch() ?: null;
    if ($byEmail) {
      $pdo->prepare('UPDATE users SET oauth_provider = ?, oauth_id = ? WHERE id = ?')
        ->execute([$providerName, $profile['id'], $byEmail['id']]);
      $user = $byEmail;
    }
  } catch (Throwable $e) {}
}

if (!$user) {
  // Новый аккаунт только если не залогинен
  $base = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($profile['name'] ?? '')) ?: ($providerName . '_' . substr($profile['id'], 0, 12));
  $username = substr($base, 0, 24);
  if ($username === '') $username = $providerName . '_' . substr($profile['id'], 0, 12);
  try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
      $username .= '_' . substr(bin2hex(random_bytes(3)), 0, 5);
    }
  } catch (Throwable $e) {}
  try {
    $stmt = $pdo->prepare(
      'INSERT INTO users (email, username, oauth_provider, oauth_id, avatar, oauth_access_token) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
      $profile['email'],
      $username,
      $providerName,
      $profile['id'],
      $profile['avatar'],
      $tokenData['access_token'],
    ]);
    $userId = (int)$pdo->lastInsertId();
  } catch (Throwable $e) {
    flash_set('error', 'Не удалось создать пользователя: ' . $e->getMessage());
    redirect('/auth/login.php');
  }
} else {
  if (!empty($user['is_banned'])) {
    flash_set('error', 'Аккаунт заблокирован');
    redirect('/auth/login.php');
  }
  $userId = (int)$user['id'];
  try {
    $pdo->prepare('UPDATE users SET oauth_access_token = ? WHERE id = ?')
      ->execute([$tokenData['access_token'], $userId]);
  } catch (Throwable $e) {}
}

// 2FA если включена
if (function_exists('get_setting') && get_setting('force_2fa_enabled', '0') === '1') {
  $_SESSION['pending_2fa_user_id'] = $userId;
  $totpEnabled = false;
  try {
    $st = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ?');
    $st->execute([$userId]);
    $totpEnabled = (bool)$st->fetchColumn();
  } catch (Throwable $e) {}
  $token = function_exists('make_2fa_token') ? make_2fa_token($userId) : '';
  redirect(($totpEnabled ? '/auth/2fa_verify.php' : '/auth/2fa_setup.php') . ($token ? ('?t=' . urlencode($token)) : ''));
}

login_user($userId);
$next = $_SESSION['login_next'] ?? '/dashboard.php';
unset($_SESSION['login_next']);
redirect($next);
