<?php
/**
 * Личные боты модераторов (1 на модератора) + нейрохам-ответы.
 * Токен видит/меняет только владелец; админ видит «подключён / нет», plaintext никогда.
 */
require_once __DIR__ . '/secret_crypto.php';
if (!function_exists('bots_http_json')) {
  require_once __DIR__ . '/bots_http.php';
}
if (!function_exists('bots_http_json')) {
  require_once __DIR__ . '/social_bots.php';
}

function mod_bots_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS mod_bots (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        tg_enabled TINYINT(1) NOT NULL DEFAULT 0,
        tg_token_enc TEXT NULL,
        tg_chat_id VARCHAR(64) NULL,
        auto_resource TINYINT(1) NOT NULL DEFAULT 1,
        auto_video TINYINT(1) NOT NULL DEFAULT 0,
        auto_forum TINYINT(1) NOT NULL DEFAULT 0,
        neuroham_enabled TINYINT(1) NOT NULL DEFAULT 1,
        bot_username VARCHAR(64) NULL,
        updated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}
  try {
    db()->exec(
      "CREATE TABLE IF NOT EXISTS mod_bots_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        body TEXT NOT NULL,
        title VARCHAR(255) NULL,
        link VARCHAR(500) NULL,
        status ENUM('pending','sent','error') NOT NULL DEFAULT 'pending',
        error_text VARCHAR(500) NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        KEY idx_uid_status (user_id, status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
  } catch (Throwable $e) {}
}

function mod_bots_is_moderator(?array $user): bool {
  if (!$user) return false;
  $role = $user['role'] ?? '';
  return in_array($role, ['moderator', 'admin'], true)
    || (function_exists('is_forum_moderator') && is_forum_moderator($user));
}

/** Только свой ряд. Админ чужие токены не читает. */
function mod_bots_get(int $userId): ?array {
  mod_bots_ensure_schema();
  if ($userId <= 0) return null;
  try {
    $st = db()->prepare('SELECT * FROM mod_bots WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function mod_bots_save(int $userId, array $data): array {
  if ($userId <= 0) return ['ok' => false, 'error' => 'Нет пользователя'];
  mod_bots_ensure_schema();
  $existing = mod_bots_get($userId) ?: [];

  $tokenEnc = $existing['tg_token_enc'] ?? null;
  $newTok = function_exists('bots_clean_token') ? bots_clean_token((string)($data['tg_token'] ?? '')) : trim((string)($data['tg_token'] ?? ''));
  if ($newTok !== '' && strpos($newTok, '•') === false) {
    // формат токена Telegram: 123456:ABC...
    if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{25,}$/', $newTok)) {
      return ['ok' => false, 'error' => 'Токен не похож на Telegram Bot API token'];
    }
    try {
      $tokenEnc = bots_encrypt_secret($newTok);
    } catch (Throwable $e) {
      return ['ok' => false, 'error' => 'Не удалось зашифровать токен'];
    }
  }
  if (!empty($data['tg_token_clear'])) {
    $tokenEnc = null;
  }

  $chat = trim((string)($data['tg_chat_id'] ?? ''));
  $enabled = !empty($data['tg_enabled']) ? 1 : 0;
  $neuro = !empty($data['neuroham_enabled']) ? 1 : 0;
  $autoR = !empty($data['auto_resource']) ? 1 : 0;
  $autoV = !empty($data['auto_video']) ? 1 : 0;
  $autoF = !empty($data['auto_forum']) ? 1 : 0;

  $username = $existing['bot_username'] ?? null;
  if ($tokenEnc) {
    try {
      $plain = bots_decrypt_secret($tokenEnc);
      $me = bots_http_json('https://api.telegram.org/bot' . rawurlencode($plain) . '/getMe');
      if (!empty($me['ok'])) {
        $username = $me['result']['username'] ?? $username;
      }
    } catch (Throwable $e) {}
  }

  try {
    if ($existing) {
      db()->prepare(
        'UPDATE mod_bots SET tg_enabled=?, tg_token_enc=?, tg_chat_id=?, auto_resource=?, auto_video=?, auto_forum=?,
         neuroham_enabled=?, bot_username=?, updated_at=NOW() WHERE user_id=?'
      )->execute([$enabled, $tokenEnc, $chat !== '' ? $chat : null, $autoR, $autoV, $autoF, $neuro, $username, $userId]);
    } else {
      db()->prepare(
        'INSERT INTO mod_bots (user_id, tg_enabled, tg_token_enc, tg_chat_id, auto_resource, auto_video, auto_forum, neuroham_enabled, bot_username, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())'
      )->execute([$userId, $enabled, $tokenEnc, $chat !== '' ? $chat : null, $autoR, $autoV, $autoF, $neuro, $username]);
    }
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'Ошибка БД'];
  }
  return ['ok' => true, 'error' => null, 'username' => $username];
}

function mod_bots_token(int $userId): string {
  $row = mod_bots_get($userId);
  if (!$row || empty($row['tg_token_enc'])) return '';
  try {
    return bots_decrypt_secret((string)$row['tg_token_enc']);
  } catch (Throwable $e) {
    return '';
  }
}

function mod_bots_send(int $userId, string $text, ?string $chatOverride = null): array {
  $row = mod_bots_get($userId);
  if (!$row || empty($row['tg_enabled'])) {
    return ['ok' => false, 'error' => 'Бот выключен'];
  }
  $token = mod_bots_token($userId);
  $chat = $chatOverride !== null ? $chatOverride : trim((string)($row['tg_chat_id'] ?? ''));
  if ($token === '' || $chat === '') {
    return ['ok' => false, 'error' => 'Нет токена или chat_id'];
  }
  $res = bots_http_json('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage', [
    'chat_id' => $chat,
    'text' => mb_substr($text, 0, 4096),
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => '0',
  ]);
  return !empty($res['ok']) ? ['ok' => true] : ['ok' => false, 'error' => $res['description'] ?? 'fail'];
}

/* ---------- Нейрохам: мягкие отчитывания ---------- */

function mod_bots_neuroham_phrases(): array {
  return [
    'Эй, полегче. Так делать не стоит — давай без этого.',
    'Зачем ты так? Не надо, правда. Можно же нормально.',
    'Стоп. Это уже лишнее. Переформулируй спокойнее.',
    'Не остыл ещё? Остынь и напиши по делу, без крика.',
    'Так не пойдёт. Уважай людей на площадке — и тебя будут уважать.',
    'Брат, ты перегибаешь. Давай без хамства, ок?',
    'Я бы на твоём месте так не писал. Перепиши спокойнее.',
    'Это уже буянство. Предупреждение: держи тон.',
    'Не надо устраивать цирк. Вопрос по существу — и всё будет ок.',
    'Достаточно. Пиши нормально, без оскорблений.',
  ];
}

function mod_bots_is_toxic(string $text): bool {
  $t = mb_strtolower($text);
  $bad = [
    'бля', 'хуй', 'пизд', 'ебан', 'ёбан', 'сука', 'мудил', 'дебил', 'идиот',
    'убить', 'зарежу', 'сдохн', 'мраз', 'чмо', 'даун ', 'retard', 'fuck', 'shit',
    'пошел нах', 'пошёл нах', 'нахуй', 'нахуя', 'гандон', 'шлюх',
    'заткнись', 'заткнись', 'урод', 'тварь', 'вырод', 'лох ', 'лошара',
  ];
  foreach ($bad as $w) {
    if (mb_strpos($t, $w) !== false) return true;
  }
  // МНОГО КАПСА
  $letters = preg_replace('/[^a-zA-Zа-яА-ЯёЁ]/u', '', $text);
  if (mb_strlen($letters) >= 12) {
    $upper = preg_replace('/[^A-ZА-ЯЁ]/u', '', $text);
    if (mb_strlen($upper) / max(1, mb_strlen($letters)) > 0.7) return true;
  }
  return false;
}

function mod_bots_neuroham_reply(): string {
  $list = mod_bots_neuroham_phrases();
  return $list[random_int(0, count($list) - 1)];
}

/**
 * Ответ во входящих: личка И группы/супергруппы.
 * $neuro — нейрохам в чатах при токсике.
 * В группах на обычный текст без токсика молчит (не спамит).
 */
function mod_bots_handle_inbound_with_token(string $tokenPlain, array $update, bool $neuro = true, ?string $siteUrl = null): void {
  $msg = $update['message'] ?? $update['edited_message'] ?? null;
  if (!$msg || empty($msg['chat']['id'])) return;

  $chatId = $msg['chat']['id'];
  $chatType = (string)($msg['chat']['type'] ?? 'private'); // private|group|supergroup|channel
  $isGroup = in_array($chatType, ['group', 'supergroup'], true);
  $text = trim((string)($msg['text'] ?? $msg['caption'] ?? ''));
  if ($text === '') return;

  $lower = mb_strtolower($text);
  $reply = null;
  $replyTo = isset($msg['message_id']) ? (int)$msg['message_id'] : null;

  // Команды — и в личке, и в чатах
  if (strpos($lower, '/start') === 0) {
    $reply = "👋 Бот StreamLive на связи.\n/help — команды\nВ чатах: реагирую на буянство (нейрохам).";
  } elseif (strpos($lower, '/help') === 0) {
    $reply = "Команды: /start, /help, /site, /neuroham\n"
      . "В группах при мате/оскорблениях — мягкий отлуп.\n"
      . "Чтобы бот видел все сообщения в группе: отключи Privacy у @BotFather → /setprivacy → Disable.";
  } elseif (strpos($lower, '/site') === 0) {
    $reply = $siteUrl ?: 'Сайт платформы в описании бота.';
  } elseif (strpos($lower, '/neuroham') === 0) {
    $reply = $neuro
      ? "Нейрохам включён: в чатах и личке на буйных отвечаю спокойно, но твёрдо."
      : "Нейрохам выключен в настройках этого бота.";
  } elseif ($neuro && mod_bots_is_toxic($text)) {
    // В чатах и личке — нейрохам
    $who = '';
    if ($isGroup && !empty($msg['from']['username'])) {
      $who = '@' . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$msg['from']['username']) . ', ';
    } elseif ($isGroup && !empty($msg['from']['first_name'])) {
      $who = trim((string)$msg['from']['first_name']) . ', ';
    }
    $reply = $who . mod_bots_neuroham_reply();
  } elseif (!$isGroup) {
    // Только в личке — короткий ответ на обычный текст
    $reply = "Принято. Вопрос по площадке — модератору на сайте."
      . ($siteUrl ? "\n🌐 " . $siteUrl : '');
  } else {
    // группа, не токсик, не команда — молчим
    return;
  }

  if ($reply === null || $reply === '') return;

  $post = [
    'chat_id' => $chatId,
    'text' => mb_substr($reply, 0, 4096),
  ];
  // В группе отвечаем реплаем на сообщение буяна
  if ($isGroup && $replyTo) {
    $post['reply_to_message_id'] = $replyTo;
    $post['allow_sending_without_reply'] = '1';
  }

  bots_http_json(
    'https://api.telegram.org/bot' . rawurlencode($tokenPlain) . '/sendMessage',
    $post
  );
}

/** Новость: ресурс / видео / тема → очередь всех мод-ботов с флагом */
function mod_bots_broadcast_new_content(string $type, string $title, string $url): void {
  mod_bots_ensure_schema();
  if ($type === 'video') $col = 'auto_video';
  elseif ($type === 'forum') $col = 'auto_forum';
  else $col = 'auto_resource';
  $labels = [
    'resource' => "📦 Новый ресурс\n<title>\nСкачивайте, наслаждайтесь 👇\n<link>",
    'video' => "🎬 Новое видео\n<title>\nСмотрите на площадке 👇\n<link>",
    'forum' => "📝 Новая тема на форуме\n<title>\n<link>",
  ];
  $tpl = $labels[$type] ?? $labels['resource'];
  $body = str_replace(['<title>', '<link>'], [$title, $url], $tpl);

  try {
    $rows = db()->query(
      "SELECT user_id FROM mod_bots WHERE tg_enabled = 1 AND tg_token_enc IS NOT NULL AND `{$col}` = 1"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) {
    $rows = [];
  }

  foreach ($rows as $uid) {
    $uid = (int)$uid;
    try {
      db()->prepare(
        'INSERT INTO mod_bots_queue (user_id, body, title, link, status) VALUES (?,?,?,?,\'pending\')'
      )->execute([$uid, $body, $title, $url]);
    } catch (Throwable $e) {}
  }
}

function mod_bots_process_queue(int $limit = 20): array {
  mod_bots_ensure_schema();
  $st = db()->query(
    "SELECT * FROM mod_bots_queue WHERE status='pending' AND attempts < 5 ORDER BY id ASC LIMIT " . (int)$limit
  );
  $rows = $st ? ($st->fetchAll() ?: []) : [];
  $ok = 0; $err = 0;
  foreach ($rows as $row) {
    $id = (int)$row['id'];
    $uid = (int)$row['user_id'];
    db()->prepare('UPDATE mod_bots_queue SET attempts = attempts + 1 WHERE id = ?')->execute([$id]);
    $r = mod_bots_send($uid, (string)$row['body']);
    if (!empty($r['ok'])) {
      db()->prepare("UPDATE mod_bots_queue SET status='sent', sent_at=NOW(), error_text=NULL WHERE id=?")->execute([$id]);
      $ok++;
    } else {
      db()->prepare("UPDATE mod_bots_queue SET status='error', error_text=? WHERE id=?")
        ->execute([mb_substr($r['error'] ?? 'fail', 0, 500), $id]);
      $err++;
    }
  }
  return ['done' => $ok, 'error' => $err];
}
