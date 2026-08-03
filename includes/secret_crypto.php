<?php
/**
 * AES-256-GCM для токенов ботов.
 * Ключ: 1) storage/bots_secret.key  2) fallback из settings/config (если нет записи на диск).
 */

function bots_secret_key_path(): string {
  $dir = dirname(__DIR__) . '/storage';
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  return $dir . '/bots_secret.key';
}

function bots_derive_fallback_key(): string {
  // Стабильный ключ без файла: хэш от известных констант сайта
  $parts = [];
  if (defined('DB_PASS')) $parts[] = (string)DB_PASS;
  if (defined('DB_NAME')) $parts[] = (string)DB_NAME;
  if (defined('DB_USER')) $parts[] = (string)DB_USER;
  if (defined('SITE_NAME')) $parts[] = (string)SITE_NAME;
  $parts[] = 'streamlive-bots-v1';
  try {
    if (function_exists('get_setting')) {
      $k = (string)get_setting('bots_crypto_seed', '');
      if ($k === '') {
        $k = bin2hex(random_bytes(16));
        @set_setting('bots_crypto_seed', $k);
      }
      $parts[] = $k;
    }
  } catch (Throwable $e) {}
  return substr(hash('sha256', implode('|', $parts), true), 0, 32);
}

function bots_secret_key(): string {
  static $key = null;
  if ($key !== null) return $key;

  $path = bots_secret_key_path();
  if (is_file($path)) {
    $raw = @file_get_contents($path);
    if (is_string($raw) && strlen($raw) >= 32) {
      $key = substr($raw, 0, 32);
      return $key;
    }
  }

  // Пытаемся записать файл
  $generated = random_bytes(32);
  $written = @file_put_contents($path, $generated, LOCK_EX);
  if ($written !== false && is_file($path) && filesize($path) >= 32) {
    @chmod($path, 0600);
    $key = $generated;
    return $key;
  }

  // Shared-hosting: диск недоступен → стабильный fallback (не меняется между запросами)
  $key = bots_derive_fallback_key();
  return $key;
}

function bots_encrypt_secret(string $plain): string {
  if ($plain === '') return '';
  $key = bots_secret_key();
  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
  if ($cipher === false) {
    // fallback без GCM (старый PHP) — AES-256-CBC
    $iv2 = random_bytes(16);
    $c = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv2);
    if ($c === false) throw new RuntimeException('encrypt failed');
    return 'CBC:' . base64_encode($iv2 . $c);
  }
  return base64_encode($iv . $tag . $cipher);
}

function bots_decrypt_secret(string $payload): string {
  if ($payload === '') return '';
  $key = bots_secret_key();

  if (strpos($payload, 'CBC:') === 0) {
    $raw = base64_decode(substr($payload, 4), true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $c = substr($raw, 16);
    $p = openssl_decrypt($c, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $p === false ? '' : $p;
  }

  $raw = base64_decode($payload, true);
  if ($raw === false || strlen($raw) < 28) return '';
  $iv = substr($raw, 0, 12);
  $tag = substr($raw, 12, 16);
  $cipher = substr($raw, 28);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  return $plain === false ? '' : $plain;
}

function bots_mask_secret(string $plain): string {
  $len = strlen($plain);
  if ($len <= 8) return $len ? str_repeat('•', $len) : '';
  return str_repeat('•', max(0, $len - 6)) . substr($plain, -6);
}

/** Чистка токена после копипаста (пробелы, zero-width, BOM) */
function bots_clean_token(string $token): string {
  $token = trim($token);
  $token = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $token) ?? $token;
  $token = preg_replace('/\s+/', '', $token) ?? $token;
  return trim($token);
}
