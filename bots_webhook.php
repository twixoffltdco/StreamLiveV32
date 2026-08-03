<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/social_bots.php';
require_once __DIR__ . '/includes/mod_bots.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '[]', true);
if (!is_array($update)) {
  echo json_encode(['ok' => false]);
  exit;
}

$modId = (int)($_GET['mod'] ?? 0);
$site = '';
try {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  if ($host) $site = $scheme . '://' . $host;
} catch (Throwable $e) {}

try {
  if ($modId > 0) {
    mod_bots_ensure_schema();
    $row = mod_bots_get($modId);
    if (!$row || empty($row['tg_enabled'])) {
      echo json_encode(['ok' => true, 'skip' => 1]);
      exit;
    }
    // optional secret
    $sec = $row['webhook_secret'] ?? '';
    if ($sec !== '') {
      $hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
      if (!hash_equals((string)$sec, (string)$hdr)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
      }
    }
    $token = mod_bots_token($modId);
    if ($token === '') {
      echo json_encode(['ok' => true]);
      exit;
    }
    $neuro = !isset($row['neuroham_enabled']) || !empty($row['neuroham_enabled']);
    mod_bots_handle_inbound_with_token($token, $update, $neuro, $site);
  } else {
    // платформенный бот
    bots_ensure_schema();
    $s = bots_settings();
    $secret = (string)($s['tg_webhook_secret'] ?? '');
    if ($secret !== '') {
      $hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
      $qs = $_GET['s'] ?? '';
      if (!hash_equals($secret, (string)$hdr) && !hash_equals($secret, (string)$qs)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
      }
    }
    // нейрохам на платформенном боте
    if (function_exists('bots_handle_telegram_update')) {
      // wrap: if toxic, neuroham first via override
      $msg = $update['message'] ?? null;
      $text = is_array($msg) ? trim((string)($msg['text'] ?? '')) : '';
      $token = bots_tg_token();
      if ($token !== '' && $text !== '' && function_exists('mod_bots_is_toxic') && mod_bots_is_toxic($text)
          && (empty($s['tg_inbound_enabled']) || !empty($s['tg_inbound_enabled']))) {
        if (!preg_match('#^/(start|help|site)#i', $text)) {
          mod_bots_handle_inbound_with_token($token, $update, true, $site);
          echo json_encode(['ok' => true, 'neuro' => 1]);
          exit;
        }
      }
      bots_handle_telegram_update($update);
    }
  }
} catch (Throwable $e) {
  if (function_exists('bots_log')) {
    bots_log('telegram', false, 'webhook: ' . $e->getMessage(), $raw);
  }
}

echo json_encode(['ok' => true]);
