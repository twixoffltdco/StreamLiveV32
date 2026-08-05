<?php
/**
 * Короткие ссылки: /g/v7k2 /g/f3a1
 * Типы: v=video, f=forum, c=channel, p=profile, r=resource
 */
function shortlink_ensure_table(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS short_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(16) NOT NULL,
        target_url VARCHAR(700) NOT NULL,
        kind CHAR(1) NOT NULL DEFAULT 'x',
        ref_id INT UNSIGNED NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code (code),
        KEY idx_target (target_url(191))
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {}
}

function shortlink_base(): string {
  if (defined('SITE_URL') && SITE_URL) return rtrim((string)SITE_URL, '/');
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function shortlink_rand_code(int $len = 5): string {
  $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
  $out = '';
  for ($i = 0; $i < $len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
  }
  return $out;
}

/**
 * Вернуть короткий URL для длинного. $kind: v|f|c|p|r
 */
function shortlink_get(string $targetUrl, string $kind = 'x', ?int $refId = null): string {
  shortlink_ensure_table();
  $targetUrl = trim($targetUrl);
  if ($targetUrl === '') return shortlink_base() . '/';
  if (strpos($targetUrl, 'http') !== 0) {
    $targetUrl = shortlink_base() . '/' . ltrim($targetUrl, '/');
  }
  $kind = preg_replace('/[^a-z]/', '', strtolower($kind)) ?: 'x';
  $kind = substr($kind, 0, 1);

  try {
    $st = db()->prepare('SELECT code FROM short_links WHERE target_url = ? LIMIT 1');
    $st->execute([mb_substr($targetUrl, 0, 700)]);
    $code = $st->fetchColumn();
    if ($code) {
      return shortlink_base() . '/g/' . $code;
    }
    for ($i = 0; $i < 8; $i++) {
      $code = $kind . shortlink_rand_code(5);
      try {
        db()->prepare(
          'INSERT INTO short_links (code, target_url, kind, ref_id) VALUES (?,?,?,?)'
        )->execute([$code, mb_substr($targetUrl, 0, 700), $kind, $refId]);
        return shortlink_base() . '/g/' . $code;
      } catch (Throwable $e) {
        // collision
      }
    }
  } catch (Throwable $e) {}
  return $targetUrl;
}

function shortlink_resolve(string $code): ?string {
  shortlink_ensure_table();
  $code = preg_replace('/[^a-zA-Z0-9]/', '', $code);
  if ($code === '') return null;
  try {
    $st = db()->prepare('SELECT target_url FROM short_links WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $url = $st->fetchColumn();
    if ($url) {
      try {
        db()->prepare('UPDATE short_links SET hits = hits + 1 WHERE code = ?')->execute([$code]);
      } catch (Throwable $e) {}
      return (string)$url;
    }
  } catch (Throwable $e) {}
  return null;
}
