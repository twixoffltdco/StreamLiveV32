<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/secret_crypto.php';
require_once __DIR__ . '/../includes/bots_http.php';
if (is_file(__DIR__ . '/../includes/social_bots.php')) {
  require_once __DIR__ . '/../includes/social_bots.php';
}

$lines = [];
$lines[] = 'PHP ' . PHP_VERSION;
$lines[] = 'openssl: ' . (extension_loaded('openssl') ? 'yes' : 'NO');
$lines[] = 'curl: ' . (function_exists('curl_init') ? 'yes' : 'no');
$lines[] = 'allow_url_fopen: ' . (ini_get('allow_url_fopen') ? 'yes' : 'no');
$lines[] = 'aes-256-gcm: ' . (in_array('aes-256-gcm', openssl_get_cipher_methods(), true) ? 'yes' : 'no');

$path = bots_secret_key_path();
$dir = dirname($path);
$lines[] = 'storage dir: ' . $dir;
$lines[] = 'storage exists: ' . (is_dir($dir) ? 'yes' : 'NO');
$lines[] = 'storage writable: ' . (is_dir($dir) && is_writable($dir) ? 'yes' : 'NO — будет fallback-ключ');
$lines[] = 'key file: ' . (is_file($path) ? ('yes, ' . filesize($path) . 'b') : 'missing');

$encOk = false;
try {
  $e = bots_encrypt_secret('test-token-123');
  $d = bots_decrypt_secret($e);
  $encOk = ($d === 'test-token-123');
  $lines[] = 'encrypt/decrypt roundtrip: ' . ($encOk ? 'OK' : 'FAIL');
} catch (Throwable $ex) {
  $lines[] = 'encrypt error: ' . $ex->getMessage();
}

$tgResult = null;
$rawToken = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $rawToken = bots_clean_token((string)($_POST['token'] ?? ''));
  if ($rawToken !== '') {
    // Live getMe WITHOUT saving — proves network + token
    $tgResult = bots_http_json('https://api.telegram.org/bot' . rawurlencode($rawToken) . '/getMe');
    if (!empty($_POST['save_platform']) && function_exists('bots_save_settings')) {
      bots_save_settings([
        'tg_enabled' => 1,
        'tg_token' => $rawToken,
        'tg_chat_id' => trim((string)($_POST['chat_id'] ?? '')),
        'tg_inbound_enabled' => 1,
      ]);
      // set webhook
      if (function_exists('bots_telegram_set_webhook')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/bots_webhook.php';
        $wh = bots_telegram_set_webhook($url, null);
        $lines[] = 'setWebhook: ' . json_encode($wh, JSON_UNESCAPED_UNICODE);
      }
    }
  }
}

// stored token test
$storedTest = null;
if (function_exists('bots_tg_token')) {
  $st = bots_tg_token();
  $lines[] = 'stored token length: ' . strlen($st);
  if ($st !== '') {
    $storedTest = bots_http_json('https://api.telegram.org/bot' . rawurlencode($st) . '/getMe');
  }
}

require_once __DIR__ . '/_layout_start.php';
?>
<h2>Диагностика бота Telegram</h2>
<pre style="background:#111;color:#9f9;padding:12px;border-radius:8px;font-size:12px;overflow:auto"><?= e(implode("\n", $lines)) ?></pre>

<?php if ($storedTest !== null): ?>
  <div class="alert <?= !empty($storedTest['ok']) ? 'alert-success' : 'alert-error' ?>">
    Сохранённый токен getMe:
    <?= !empty($storedTest['ok']) ? ('OK @' . e($storedTest['result']['username'] ?? '')) : e($storedTest['description'] ?? $storedTest['error'] ?? 'fail') ?>
  </div>
<?php endif; ?>

<?php if ($tgResult !== null): ?>
  <div class="alert <?= !empty($tgResult['ok']) ? 'alert-success' : 'alert-error' ?>">
    Введённый токен getMe:
    <?= !empty($tgResult['ok']) ? ('OK @' . e($tgResult['result']['username'] ?? '')) : e($tgResult['description'] ?? $tgResult['error'] ?? 'fail') ?>
  </div>
<?php endif; ?>

<form method="POST" class="form-card" style="max-width:560px;margin-top:16px">
  <?= csrf_field() ?>
  <p style="font-size:13px;color:var(--text-dim)">Вставь токен сюда — проверка <b>сразу в Telegram API</b>, без угадываний.</p>
  <label>Токен</label>
  <input type="text" name="token" autocomplete="off" spellcheck="false" style="width:100%;padding:10px" placeholder="123456789:AA..." required>
  <label>Chat ID (канал @name или -100...)</label>
  <input type="text" name="chat_id" style="width:100%;padding:10px" placeholder="@channel">
  <label style="display:flex;gap:8px;align-items:center;margin:10px 0">
    <input type="checkbox" name="save_platform" value="1" checked> Сохранить в платформенного бота + webhook
  </label>
  <button class="btn btn-primary" type="submit">Проверить и сохранить</button>
</form>

<p style="font-size:12px;color:var(--text-dim);margin-top:16px">
  Если getMe = OK, а «не отвечает» — нужен HTTPS и кнопка setWebhook / напиши боту /start.<br>
  Если getMe = Unauthorized — токен неверный или отозван у @BotFather.<br>
  Если curl/fopen fail — хостинг режет исходящие запросы к api.telegram.org.
</p>
<?php require_once __DIR__ . '/_layout_end.php'; ?>
