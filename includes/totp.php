<?php
// Простая реализация TOTP (RFC 6238) без внешних зависимостей.
// Совместима с Google Authenticator, Authy и любым другим стандартным TOTP-приложением.
class Totp {
  private static function base32Encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $char) {
      $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
      $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
      $output .= $alphabet[bindec($chunk)];
    }
    return $output;
  }

  private static function base32Decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $char) {
      $pos = strpos($alphabet, $char);
      if ($pos === false) continue;
      $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
      if (strlen($byte) < 8) continue;
      $bytes .= chr(bindec($byte));
    }
    return $bytes;
  }

  public static function generateSecret(int $length = 20): string {
    return self::base32Encode(random_bytes($length));
  }

  public static function provisioningUri(string $secret, string $accountName, string $issuer): string {
    $label = rawurlencode($issuer . ':' . $accountName);
    $query = http_build_query([
      'secret' => $secret,
      'issuer' => $issuer,
      'algorithm' => 'SHA1',
      'digits' => 6,
      'period' => 30,
    ]);
    return "otpauth://totp/{$label}?{$query}";
  }

  private static function code(string $secret, int $counter): string {
    $key = self::base32Decode($secret);
    $bin = pack('N*', 0) . pack('N*', $counter); // 64-битный счётчик, big-endian
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
      | ((ord($hash[$offset + 1]) & 0xff) << 16)
      | ((ord($hash[$offset + 2]) & 0xff) << 8)
      | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($truncated % 1000000), 6, '0', STR_PAD_LEFT);
  }

  // Проверка кода с допуском ±1 шаг (30 сек) на рассинхрон времени телефона
  public static function verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\D+/', '', (string)$code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $counter = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
      if (hash_equals(self::code($secret, $counter + $i), $code)) return true;
    }
    return false;
  }
}
