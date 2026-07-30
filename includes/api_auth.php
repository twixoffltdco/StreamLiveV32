<?php
// API-ключи — как просил: у авторизованного пользователя в личном кабинете свой ключ,
// который даёт гарантированный доступ без троттлинга ("без перерыва"). Без ключа — доступ
// зависит от текущей нагрузки сайта, а во время DDoS-атаки запросы без ключа блокируются
// на 100 часов (экономим ресурсы сервера на аутентифицированных, предсказуемых клиентов).
// Ключ живёт 30 дней и перевыпускается сам при следующем обращении после истечения —
// отдельный крон не нужен, всё происходит внутри api_key_get_or_rotate().

const API_KEY_LIFETIME_DAYS = 30;
const API_KEYLESS_ATTACK_BLOCK_HOURS = 100;
const API_LEAKED_KEY_DISTINCT_IPS = 8;      // столько разных IP за час — подозрение на утечку
const API_LEAKED_KEY_WINDOW_MINUTES = 60;

function api_ensure_schema(): void {
  static $checked = false;
  if ($checked) return;
  $checked = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        api_key VARCHAR(64) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        rotated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_flagged_leaked TINYINT(1) NOT NULL DEFAULT 0,
        KEY idx_user (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    db()->exec(
      "CREATE TABLE IF NOT EXISTS api_key_usage_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        api_key_id INT NOT NULL,
        ip VARCHAR(45) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_key_time (api_key_id, created_at),
        FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (\Throwable $e) { /* нет прав CREATE — залей sql/migrations/037_api_keys.sql руками */ }
}

// Достаёт текущий ключ пользователя, или создаёт новый, если его никогда не было, или
// перевыпускает, если старому больше 30 дней (ротация "в целях безопасности", как просили).
function api_key_get_or_rotate(int $userId): string {
  api_ensure_schema();
  $stmt = db()->prepare('SELECT * FROM api_keys WHERE user_id = ?');
  $stmt->execute([$userId]);
  $row = $stmt->fetch();

  $needsRotation = !$row || (time() - strtotime($row['rotated_at'])) > API_KEY_LIFETIME_DAYS * 86400;
  if (!$needsRotation) return $row['api_key'];

  $newKey = 'sk_' . bin2hex(random_bytes(24));
  if ($row) {
    db()->prepare('UPDATE api_keys SET api_key = ?, rotated_at = NOW(), is_flagged_leaked = 0 WHERE id = ?')
      ->execute([$newKey, $row['id']]);
  } else {
    db()->prepare('INSERT INTO api_keys (user_id, api_key) VALUES (?, ?)')->execute([$userId, $newKey]);
  }
  return $newKey;
}

// Сколько дней осталось до автоматической ротации — показываем в личном кабинете.
function api_key_days_until_rotation(int $userId): ?int {
  api_ensure_schema();
  $stmt = db()->prepare('SELECT rotated_at FROM api_keys WHERE user_id = ?');
  $stmt->execute([$userId]);
  $row = $stmt->fetch();
  if (!$row) return null;
  $elapsedDays = (time() - strtotime($row['rotated_at'])) / 86400;
  return max(0, (int)ceil(API_KEY_LIFETIME_DAYS - $elapsedDays));
}

function api_key_lookup(string $key): ?array {
  api_ensure_schema();
  $stmt = db()->prepare('SELECT * FROM api_keys WHERE api_key = ?');
  $stmt->execute([$key]);
  $row = $stmt->fetch();
  if (!$row) return null;
  // Ключ старше 30 дней и ещё не перевыпущен владельцем через личный кабинет — считаем
  // недействительным (владелец должен зайти и перевыпустить, это и есть "сброс раз в 30 дней").
  if ((time() - strtotime($row['rotated_at'])) > API_KEY_LIFETIME_DAYS * 86400) return null;
  return $row;
}

// Детект утечки: если один и тот же ключ за последний час использовался с подозрительно
// большого числа РАЗНЫХ IP — это не похоже на одного легитимного клиента (сервер/скрипт
// обычно стучится с одного-двух адресов), похоже, что ключ кто-то расшарил/утёк в открытый
// доступ. Помечаем и в следующий раз возвращаем предупреждение вместо тихой работы.
function api_key_check_leaked(int $apiKeyId): bool {
  $stmt = db()->prepare(
    'SELECT COUNT(DISTINCT ip) FROM api_key_usage_log WHERE api_key_id = ? AND created_at >= ?'
  );
  $stmt->execute([$apiKeyId, date('Y-m-d H:i:s', time() - API_LEAKED_KEY_WINDOW_MINUTES * 60)]);
  $distinctIps = (int)$stmt->fetchColumn();
  if ($distinctIps >= API_LEAKED_KEY_DISTINCT_IPS) {
    db()->prepare('UPDATE api_keys SET is_flagged_leaked = 1 WHERE id = ?')->execute([$apiKeyId]);
    return true;
  }
  return false;
}

function api_key_log_usage(int $apiKeyId, string $ip): void {
  try {
    db()->prepare('INSERT INTO api_key_usage_log (api_key_id, ip) VALUES (?, ?)')->execute([$apiKeyId, $ip]);
  } catch (\Throwable $e) { }
}

// ---- Главная точка входа для API-эндпоинтов ----
//
// Возвращает массив:
//   ['ok' => true, 'authenticated' => bool, 'warning' => ?string]  — можно продолжать
//   ['ok' => false, ...] — уже отправлен HTTP-ответ с ошибкой, эндпоинт должен exit
//
// Использование в API-файле:
//   $apiState = api_guard();
//   if (!$apiState['ok']) exit; // ответ уже отправлен
function api_guard(): array {
  api_ensure_schema();
  $ip = function_exists('antibot_client_ip') ? antibot_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
  $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '';

  if ($providedKey) {
    $keyRow = api_key_lookup($providedKey);
    if (!$keyRow) {
      http_response_code(401);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'Ключ недействителен или устарел (ротация раз в 30 дней) — перевыпустите его в личном кабинете.']);
      return ['ok' => false];
    }
    api_key_log_usage((int)$keyRow['id'], $ip);
    $leaked = api_key_check_leaked((int)$keyRow['id']);
    // С валидным ключом — гарантированный доступ без троттлинга ("без перерыва"), даже
    // во время DDoS-атаки: аутентифицированный клиент предсказуем и не в приоритете для блокировки.
    return [
      'ok' => true,
      'authenticated' => true,
      'warning' => $leaked
        ? 'ВНИМАНИЕ: этот API-ключ используется с необычно большого числа разных адресов — похоже, он стал общедоступным (утёк). Это небезопасно: держите ключ в надёжном месте (переменные окружения, секрет-хранилище), не публикуйте в открытом репозитории или на форуме. Перевыпустите ключ в личном кабинете как можно скорее.'
        : null,
    ];
  }

  // Без ключа — троттлинг по нагруженности: переиспользуем уже существующий файловый
  // circuit breaker (includes/ddos_shield.php), а во время активной DDoS-атаки — отдельная
  // усиленная блокировка на 100 часов конкретно для запросов без ключа, независимо от
  // обычного антибота (тот считает по гостевым просмотрам страниц, это про API-нагрузку).
  if (function_exists('ddos_under_attack_mode_enabled') && ddos_under_attack_mode_enabled()) {
    $blocked = api_keyless_attack_block_check($ip);
    if ($blocked) {
      http_response_code(503);
      header('Content-Type: application/json; charset=utf-8');
      header('Retry-After: ' . (API_KEYLESS_ATTACK_BLOCK_HOURS * 3600));
      echo json_encode([
        'ok' => false,
        'error' => 'Сайт сейчас в режиме защиты от атаки — запросы без API-ключа временно заблокированы на ' . API_KEYLESS_ATTACK_BLOCK_HOURS . ' часов в целях экономии ресурсов. С действительным ключом ограничение не действует.',
      ]);
      return ['ok' => false];
    }
  }

  return ['ok' => true, 'authenticated' => false, 'warning' => null];
}

// Усиленная блокировка запросов БЕЗ ключа во время атаки — переиспользует таблицу
// antibot_ip_log (уже есть у антибота), но с отдельным префиксом в ip, чтобы не путать
// со счётчиком обычных гостевых просмотров страниц с этого же адреса.
function api_keyless_attack_block_check(string $ip): bool {
  try {
    $key = 'apikeyless:' . $ip;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT blocked_until FROM antibot_ip_log WHERE ip = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if ($row && $row['blocked_until'] && strtotime($row['blocked_until']) > time()) return true;

    $blockedUntil = date('Y-m-d H:i:s', time() + API_KEYLESS_ATTACK_BLOCK_HOURS * 3600);
    if ($row) {
      $pdo->prepare('UPDATE antibot_ip_log SET blocked_until = ? WHERE ip = ?')->execute([$blockedUntil, $key]);
    } else {
      $pdo->prepare('INSERT INTO antibot_ip_log (ip, request_count, window_start, blocked_until) VALUES (?, 1, ?, ?)')
        ->execute([$key, date('Y-m-d H:i:s'), $blockedUntil]);
    }
    return true;
  } catch (\Throwable $e) {
    return false; // БД недоступна — не блокируем из-за нашей же временной проблемы
  }
}
