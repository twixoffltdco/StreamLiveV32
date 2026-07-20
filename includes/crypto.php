<?php
// Раньше требовалась ручная правка config.php (APP_ENCRYPTION_KEY). Теперь ключ
// создаётся сам при первом использовании и хранится в отдельном файле вне git —
// ничего в config.php вписывать не нужно.

function app_encryption_key(): string {
  $keyFile = __DIR__ . '/../storage/app_key.php';

  if (!file_exists($keyFile)) {
    $dir = dirname($keyFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Файл начинается с "<?php exit; ..." — так он не отдаёт содержимое, даже если
    // папку storage/ вдруг откроют напрямую через веб без .htaccess-защиты.
    $key = bin2hex(random_bytes(32));
    file_put_contents($keyFile, "<?php exit; // {$key}\n");
    chmod($keyFile, 0600);
  }

  $contents = file_get_contents($keyFile);
  preg_match('/exit;\s*\/\/\s*([a-f0-9]{64})/', $contents, $m);
  if (!isset($m[1])) {
    throw new RuntimeException('Не удалось прочитать ключ шифрования из storage/app_key.php — удалите файл, он пересоздастся');
  }
  return $m[1];
}

function encrypt_secret(string $plain): string {
  $iv = random_bytes(16);
  $cipher = openssl_encrypt($plain, 'aes-256-cbc', hash('sha256', app_encryption_key(), true), OPENSSL_RAW_DATA, $iv);
  return base64_encode($iv . $cipher);
}

function decrypt_secret(string $encoded): ?string {
  $raw = base64_decode($encoded, true);
  if ($raw === false || strlen($raw) < 17) return null;
  $iv = substr($raw, 0, 16);
  $cipher = substr($raw, 16);
  $plain = openssl_decrypt($cipher, 'aes-256-cbc', hash('sha256', app_encryption_key(), true), OPENSSL_RAW_DATA, $iv);
  return $plain === false ? null : $plain;
}
