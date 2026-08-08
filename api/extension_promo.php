<?php
/**
 * Обмен монет расширения на одноразовый промокод.
 * Монеты считаются на клиенте; сервер выдаёт код и помечает username.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once dirname(__DIR__) . '/includes/functions.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$username = trim((string)($input['username'] ?? ''));
if ($username === '' || !preg_match('/^[\w.\-]{2,32}$/u', $username)) {
  echo json_encode(['ok' => false, 'error' => 'Укажите корректный username']);
  exit;
}

try {
  db()->exec(
    "CREATE TABLE IF NOT EXISTS extension_promos (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      username VARCHAR(64) NOT NULL,
      code VARCHAR(32) NOT NULL,
      used TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_user (user_id),
      UNIQUE KEY uq_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
  );
} catch (Throwable $e) {}

try {
  $st = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
  $st->execute([$username]);
  $uid = (int)$st->fetchColumn();
  if ($uid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Пользователь не найден. Сначала зарегистрируйтесь на сайте.']);
    exit;
  }

  $st = db()->prepare('SELECT code FROM extension_promos WHERE user_id = ? LIMIT 1');
  $st->execute([$uid]);
  $exist = $st->fetchColumn();
  if ($exist) {
    echo json_encode(['ok' => true, 'code' => $exist, 'note' => 'already']);
    exit;
  }

  $code = 'SL' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
  db()->prepare('INSERT INTO extension_promos (user_id, username, code) VALUES (?,?,?)')
    ->execute([$uid, $username, $code]);
  echo json_encode(['ok' => true, 'code' => $code]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'Ошибка сервера. Попробуйте позже.']);
}
