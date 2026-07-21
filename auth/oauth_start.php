<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/oauth.php';

$providerName = $_GET['provider'] ?? '';
$client = OAuthClient::find($providerName);
if (isset($_GET['next'])) {
  $_SESSION['login_next'] = normalize_auth_redirect_target($_GET['next'], '/dashboard.php');
}

if (!$client) {
  http_response_code(404);
  die('Провайдер не настроен или отключён');
}

header('Location: ' . $client->authorizeUrl());
exit;
