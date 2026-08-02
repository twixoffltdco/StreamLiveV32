<?php
/**
 * Шифрование секретов (токены ботов) — AES-256-GCM.
 * Ключ в storage/bots_secret.key (вне публичного доступа; .htaccess deny).
 * В БД храним только ciphertext base64, никогда plaintext токены.
 */

function bots_secret_key_path(): string {
  $dir = dirname(__DIR__) . '/storage';
  if (!is_dir($dir)) {
    @mkdir($dir, 0750, true);
  }
  return $dir . '/bots_secret.key';
}

function bots_secret_key(): string {
  static $key = null;
  if ($key !== null) return $key;
  $path = bots_secret_key_path();
  if (is_file($path) && filesize($path) >= 32) {
    $key = file_get_contents($path);
    if (strlen($key) >= 32) {
      $key = substr($key, 0, 32);
      return $key;
    }
  }
  // сгенерировать один раз
  $key = random_bytes(32);
  @file_put_contents($path, $key, LOCK_EX);
  @chmod($path, 0600);
  return $key;
}

/** @return string base64 payload iv+tag+cipher */
function bots_encrypt_secret(string $plain): string {
  if ($plain === '') return '';
  $key = bots_secret_key();
  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
  if ($cipher === false) {
    throw new RuntimeException('encrypt failed');
  }
  return base64_encode($iv . $tag . $cipher);
}

function bots_decrypt_secret(string $payload): string {
  if ($payload === '') return '';
  $raw = base64_decode($payload, true);
  if ($raw === false || strlen($raw) < 28) return '';
  $key = bots_secret_key();
  $iv = substr($raw, 0, 12);
  $tag = substr($raw, 12, 16);
  $cipher = substr($raw, 28);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  return $plain === false ? '' : $plain;
}

/** Маска для UI: показывать только хвост */
function bots_mask_secret(string $plain): string {
  $len = strlen($plain);
  if ($len <= 8) return $len ? str_repeat('•', $len) : '';
  return str_repeat('•', max(0, $len - 6)) . substr($plain, -6);
}
