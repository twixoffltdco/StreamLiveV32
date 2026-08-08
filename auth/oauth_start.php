<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
  @session_start();
}

$providerName = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['provider'] ?? ''));
if (!empty($_GET['next'])) {
  $_SESSION['login_next'] = (string)$_GET['next'];
}

$client = OAuthClient::find($providerName);
if (!$client) {
  flash_set('error', 'Провайдер «' . $providerName . '» не настроен или выключен. Включите в /admin/oauth.php');
  redirect('/auth/login.php');
}

if (empty($client->provider['client_id']) || empty($client->provider['client_secret'])) {
  flash_set('error', 'У провайдера «' . $providerName . '» не заполнены Client ID / Secret');
  redirect('/auth/login.php');
}

redirect($client->authorizeUrl());
