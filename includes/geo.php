<?php
/**
 * Гео + анти-VPN:
 * - geo_restrict_enabled: блок не-СНГ
 * - geo_vpn_block_enabled: блок proxy/VPN/hosting IP для обычных юзеров
 * Админы и модераторы с VPN могут заходить (bypass).
 */

function geo_whitelist_countries(): array {
  return ['RU', 'BY', 'KZ', 'KG', 'TJ', 'UZ', 'AM', 'AZ', 'MD', 'TM', 'GE', 'UA'];
}

function geo_ensure_table(): void {
  try {
    db()->exec(
      'CREATE TABLE IF NOT EXISTS geo_ip_cache (
        ip VARCHAR(45) PRIMARY KEY,
        country_code VARCHAR(5) DEFAULT NULL,
        is_proxy TINYINT(1) NOT NULL DEFAULT 0,
        is_hosting TINYINT(1) NOT NULL DEFAULT 0,
        checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
  } catch (\Throwable $e) {}
  foreach (['is_proxy' => 'TINYINT(1) NOT NULL DEFAULT 0', 'is_hosting' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $col => $def) {
    try {
      db()->exec("ALTER TABLE geo_ip_cache ADD COLUMN `{$col}` {$def}");
    } catch (\Throwable $e) {}
  }
}

/**
 * @return array{country:?string,is_proxy:bool,is_hosting:bool}
 */
function geo_lookup_ip(string $ip): array {
  $empty = ['country' => null, 'is_proxy' => false, 'is_hosting' => false];
  if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
    return $empty;
  }
  geo_ensure_table();
  try {
    $stmt = db()->prepare('SELECT country_code, is_proxy, is_hosting, checked_at FROM geo_ip_cache WHERE ip = ?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if ($row && strtotime((string)$row['checked_at']) > time() - 7 * 86400) {
      return [
        'country' => $row['country_code'] ? strtoupper((string)$row['country_code']) : null,
        'is_proxy' => !empty($row['is_proxy']),
        'is_hosting' => !empty($row['is_hosting']),
      ];
    }
  } catch (\Throwable $e) {
    return $empty;
  }

  $country = null;
  $isProxy = false;
  $isHosting = false;
  $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode,proxy,hosting');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 2,
  ]);
  $resp = curl_exec($ch);
  curl_close($ch);
  if ($resp) {
    $data = json_decode($resp, true);
    if (is_array($data) && ($data['status'] ?? '') === 'success') {
      if (!empty($data['countryCode'])) $country = strtoupper((string)$data['countryCode']);
      $isProxy = !empty($data['proxy']);
      $isHosting = !empty($data['hosting']);
    }
  }

  try {
    db()->prepare(
      'INSERT INTO geo_ip_cache (ip, country_code, is_proxy, is_hosting, checked_at) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE country_code=VALUES(country_code), is_proxy=VALUES(is_proxy),
         is_hosting=VALUES(is_hosting), checked_at=VALUES(checked_at)'
    )->execute([$ip, $country, $isProxy ? 1 : 0, $isHosting ? 1 : 0, date('Y-m-d H:i:s')]);
  } catch (\Throwable $e) {}

  return ['country' => $country, 'is_proxy' => $isProxy, 'is_hosting' => $isHosting];
}

function geo_lookup_country(string $ip): ?string {
  return geo_lookup_ip($ip)['country'];
}

function geo_client_ip(): string {
  if (function_exists('antibot_client_ip')) {
    return (string)antibot_client_ip();
  }
  return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function geo_is_staff_session(): bool {
  try {
    if (empty($_SESSION['user_id'])) return false;
    $st = db()->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)$_SESSION['user_id']]);
    $role = (string)($st->fetchColumn() ?: '');
    return in_array($role, ['admin', 'moderator'], true);
  } catch (\Throwable $e) {
    return false;
  }
}

function geo_block_page(string $reason = 'geo'): void {
  http_response_code(403);
  header('Content-Type: text/html; charset=utf-8');
  $msg = $reason === 'vpn'
    ? 'Обнаружен VPN / прокси / датацентровый IP. Сайт доступен пользователям из стран СНГ с обычного (домашнего/мобильного) подключения. Отключите VPN и откройте сайт снова.'
    : 'Сайт временно доступен только для пользователей из стран СНГ.';
  echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
    . '<title>Доступ ограничен</title><meta name="robots" content="noindex, nofollow">'
    . '<style>html,body{margin:0;height:100%;background:#0b0b10;color:#f2f2f5;font-family:-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}'
    . '.box{max-width:420px;text-align:center}.box h1{font-size:20px;margin:0 0 10px}.box p{color:#9a9aa8;font-size:13px;line-height:1.55;margin:0}</style></head><body>'
    . '<div class="box"><h1>🌍 Доступ ограничен</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
  exit;
}

function geo_guard(): void {
  try {
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (strpos($path, '/admin/') === 0) return;
    if (function_exists('antibot_exempt_paths') && in_array($path, antibot_exempt_paths(), true)) return;

    $geoOn = get_setting('geo_restrict_enabled', '0') === '1';
    $vpnOn = get_setting('geo_vpn_block_enabled', '0') === '1';
    if (!$geoOn && !$vpnOn) return;

    if (geo_is_staff_session()) return;

    $ip = geo_client_ip();
    $info = geo_lookup_ip($ip);

    if ($vpnOn && ($info['is_proxy'] || $info['is_hosting'])) {
      geo_block_page('vpn');
    }

    if ($geoOn) {
      $country = $info['country'];
      if ($country === null) return;
      if (!in_array($country, geo_whitelist_countries(), true)) {
        geo_block_page('geo');
      }
    }
  } catch (\Throwable $e) {
    return;
  }
}
