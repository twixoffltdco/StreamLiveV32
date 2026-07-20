<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';

$providerName = $_GET['provider'] ?? '';
$client = OAuthClient::find($providerName);

if (!$client) {
  http_response_code(404);
  die('Провайдер не настроен или отключён');
}

header('Location: ' . $client->authorizeUrl());
exit;
