<?php
// Гео-ограничение: пускаем только страны СНГ (+соседние, которые обычно считают
// "нашим" регионом), остальных — на страницу "доступ ограничен в вашем регионе".
// Включается/выключается через настройку 'geo_restrict_enabled' в админке
// (admin/geo.php) — по умолчанию ВЫКЛЮЧЕНО, чтобы случайно никого не отрезать.

function geo_whitelist_countries(): array {
  // Классические страны СНГ + Грузия/страны, которые обычно тоже считают "своими" —
  // список можно расширить, он просто отдаётся отсюда одной точкой.
  return ['RU', 'BY', 'KZ', 'KG', 'TJ', 'UZ', 'AM', 'AZ', 'MD', 'TM', 'GE', 'UA'];
}

function geo_ensure_table(): void {
  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS geo_ip_cache (
        ip VARCHAR(45) PRIMARY KEY,
        country_code VARCHAR(5) DEFAULT NULL,
        checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
  } catch (\Throwable $e) { /* уже есть, или нет прав — тогда просто пропустим гео-проверку ниже */ }
}

// Определяет страну IP — сперва смотрим в свой кэш (чтобы не дёргать внешний сервис
// на каждый повторный визит), иначе спрашиваем бесплатный ip-api.com (без ключа).
// Если узнать не получилось — возвращает null, и в этом случае мы НЕ блокируем
// (fail-open: лучше пропустить кого-то по ошибке, чем заблокировать реального
// посетителя из-за временной недоступности внешнего сервиса).
function geo_lookup_country(string $ip): ?string {
  if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
    return null; // локальные/приватные адреса не проверяем (разработка/тесты)
  }
  geo_ensure_table();
  try {
    $stmt = db()->prepare('SELECT country_code, checked_at FROM geo_ip_cache WHERE ip = ?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    // Кэш живёт 30 дней — страна IP почти никогда не меняется так часто
    if ($row && strtotime($row['checked_at']) > time() - 30 * 86400) {
      return $row['country_code'];
    }
  } catch (\Throwable $e) { return null; }

  $country = null;
  $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=countryCode');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 2,
  ]);
  $resp = curl_exec($ch);
  curl_close($ch);
  if ($resp) {
    $data = json_decode($resp, true);
    if (!empty($data['countryCode'])) $country = strtoupper($data['countryCode']);
  }

  try {
    db()->prepare('INSERT INTO geo_ip_cache (ip, country_code, checked_at) VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE country_code = VALUES(country_code), checked_at = VALUES(checked_at)')
      ->execute([$ip, $country, date('Y-m-d H:i:s')]);
  } catch (\Throwable $e) { /* не критично, просто не закэшируем в этот раз */ }

  return $country;
}

function geo_guard(): void {
  try {
    if (get_setting('geo_restrict_enabled', '0') !== '1') return;

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    // Админку никогда не блокируем по геопризнаку — иначе админ сам себя отрежет.
    if (strpos((string)$path, '/admin/') === 0) return;
    if (in_array($path, antibot_exempt_paths(), true)) return; // те же технические/AJAX пути

    $ip = antibot_client_ip();
    $country = geo_lookup_country($ip);
    if ($country === null) return; // не смогли определить — пропускаем (fail-open)

    if (!in_array($country, geo_whitelist_countries(), true)) {
      http_response_code(403);
      header('Content-Type: text/html; charset=utf-8');
      echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Доступ ограничен</title><meta name="robots" content="noindex, nofollow">'
        . '<style>html,body{margin:0;height:100%;background:#0b0b10;color:#f2f2f5;font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}'
        . '.box{max-width:380px;text-align:center}.box h1{font-size:20px}.box p{color:#9a9aa8;font-size:13px;line-height:1.5}</style></head><body>'
        . '<div class="box"><h1>🌍 Доступ ограничен</h1><p>Сайт временно доступен только для пользователей из стран СНГ.</p></div></body></html>';
      exit;
    }
  } catch (\Throwable $e) {
    // Любая ошибка гео-проверки не должна ронять сайт — просто пропускаем на этот раз.
    return;
  }
}
